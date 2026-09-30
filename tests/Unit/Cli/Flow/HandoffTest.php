<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\KillHistoryFile;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A plan of two shards: one file, and one held directory. */
$plan = Plan::of(Revision::ref('head'), Digest::sha256Of('base'), Keys::none(), Shards::of(
    Shard::of(
        ShardId::of(1),
        Package::at(Path::root()),
        Units::of(Unit::file(Path::of('src/Money.php'))),
        Seconds::of(1.0),
        'one',
    ),
    Shard::of(
        ShardId::of(2),
        Package::at(Path::root()),
        Units::of(Unit::held(Path::of('src/Held'), Group::named('holds:src/Held'))),
        Seconds::of(1.0),
        'two',
    ),
));

/** A map of the files of both shards, and of two no shard mutates. */
$map = CoverageMap::empty()
    ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'))
    ->covered(Path::of('src/Held/A.php'), Line::of(4), TestId::of('HeldTest::a'))
    ->covered(Path::of('src/Held/B.php'), Line::of(5), TestId::of('HeldTest::b'))
    ->covered(Path::of('src/HeldToo.php'), Line::of(6), TestId::of('HeldTooTest::c'))
    ->covered(Path::of('src/Other.php'), Line::of(7), TestId::of('OtherTest::d'))
    ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.5));

it('hands each shard the lines of its own files alone, with every test and its time', function () use (
    $plan,
    $map,
): void {
    $handoff = new Handoff(Directory::at(Scratch::directory()));

    $handed = static fn(Paths $files): CoverageMap|CannotJudge => CoverageMapFile::decode(
        CoverageMapFile::encode($map->onlyFor($files)),
    );
    $written = $handoff->write($plan, $map, KillHistory::none());
    $held = $handoff->read(ShardId::of(2));

    expect($written)->toEqual(Written::to('.mutation-gate/coverage'))
        ->and($handoff->read(ShardId::of(1)))->toEqual($handed(Paths::of(Path::of('src/Money.php'))))
        ->and($held)->toEqual($handed(Paths::of(Path::of('src/Held/A.php'), Path::of('src/Held/B.php'))))
        ->and($held instanceof CoverageMap ? $held->files() : $held)
        ->toEqual(Paths::of(Path::of('src/Held/A.php'), Path::of('src/Held/B.php')))
        ->and($held instanceof CoverageMap ? $held->durationOf(TestId::of('MoneyTest::adds')) : $held)
        ->toEqual(Seconds::of(0.5));
});

it('writes each map where run reads it, as the gate\'s own format', function () use ($plan, $map): void {
    $project = Scratch::directory();
    new Handoff(Directory::at($project))->write($plan, $map, KillHistory::none());

    expect(file_exists(sprintf('%s/.mutation-gate/coverage/shard-1/map.json.gz', $project)))->toBeTrue()
        ->and(file_exists(sprintf('%s/.mutation-gate/coverage/shard-2/map.json.gz', $project)))->toBeTrue();
});

it('cannot hand a shard a map it cannot write', function () use ($plan, $map): void {
    $project = Scratch::directory();
    Scratch::write($project, '.mutation-gate/coverage/shard-2/map.json.gz/blocked', '');

    expect(new Handoff(Directory::at($project))->write($plan, $map, KillHistory::none()))
        ->toEqual(CannotJudge::because(
            sprintf('%s/.mutation-gate/coverage/shard-2/map.json.gz could not be written.', $project),
        ));
});

it('says a shard was handed no map where there is none', function (): void {
    expect(new Handoff(Directory::at(Scratch::directory()))->read(ShardId::of(4)))->toEqual(CannotJudge::because(
        'Shard 4 was handed no coverage map at .mutation-gate/coverage/shard-4/map.json.gz. '
        . 'Hand every job the plan\'s .mutation-gate/coverage.',
    ));
});

it('cannot read a map that is not a file, or not a map', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, '.mutation-gate/coverage/shard-1/map.json.gz/blocked', '');
    Scratch::write($project, '.mutation-gate/coverage/shard-2/map.json.gz', 'not a map');
    $handoff = new Handoff(Directory::at($project));

    expect($handoff->read(ShardId::of(1)))->toEqual(CannotJudge::because(
        sprintf('%s/.mutation-gate/coverage/shard-1/map.json.gz could not be read.', $project),
    ))
        ->and($handoff->read(ShardId::of(2)))->toBeInstanceOf(CannotJudge::class);
});

it('hands each shard the kill history of its own files\' functions, beside its map', function () use (
    $plan,
    $map,
): void {
    $project = Scratch::directory();
    $mutant = MutantId::hash(Path::of('src/Money.php'), 'Plus', '@@ @@', 0);
    $ranked = Ranking::of(Kills::of(TestId::of('MoneyTest::adds'), 2));
    $history = KillHistory::none()
        ->withMutant($mutant, $ranked)
        ->withFunction(Enclosing::named(Path::of('src/Money.php'), 'add'), $ranked)
        ->withFunction(Enclosing::named(Path::of('src/Held/A.php'), 'a'), $ranked)
        ->withFunction(Enclosing::named(Path::of('src/Other.php'), 'd'), $ranked);
    $handoff = new Handoff(Directory::at($project));
    $handoff->write($plan, $map, $history);

    expect($handoff->history(ShardId::of(1)))
        ->toEqual($history->onlyIn(Paths::of(Path::of('src/Money.php'))))
        ->and($handoff->history(ShardId::of(2)))
        ->toEqual($history->onlyIn(Paths::of(Path::of('src/Held/A.php'), Path::of('src/Held/B.php'))))
        ->and(file_get_contents(sprintf('%s/.mutation-gate/coverage/shard-1/killers.json', $project)))
        ->toBe(KillHistoryFile::encode($history->onlyIn(Paths::of(Path::of('src/Money.php')))));
});

it('reads a shard handed no kill history as one no test has killed anything in', function (): void {
    expect(new Handoff(Directory::at(Scratch::directory()))->history(ShardId::of(1)))->toEqual(KillHistory::none());
});

it('cannot read a kill history that is not one, or not a file', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, '.mutation-gate/coverage/shard-1/killers.json', 'not a history');
    Scratch::write($project, '.mutation-gate/coverage/shard-2/killers.json/blocked', '');
    $handoff = new Handoff(Directory::at($project));

    expect($handoff->history(ShardId::of(1)))->toBeInstanceOf(CannotJudge::class)
        ->and($handoff->history(ShardId::of(2)))->toEqual(CannotJudge::because(
            sprintf('%s/.mutation-gate/coverage/shard-2/killers.json could not be read.', $project),
        ));
});

it('cannot hand a shard a kill history it cannot write', function () use ($plan, $map): void {
    $project = Scratch::directory();
    Scratch::write($project, '.mutation-gate/coverage/shard-1/killers.json/blocked', '');

    expect(new Handoff(Directory::at($project))->write($plan, $map, KillHistory::none()))
        ->toEqual(CannotJudge::because(
            sprintf('%s/.mutation-gate/coverage/shard-1/killers.json could not be written.', $project),
        ));
});

it('hands the verdict the lines of every unit the plan considered, run, proved or carried', function () use (
    $plan,
    $map,
): void {
    $considered = $plan->considering(
        Considered::everything()
            ->proving(Units::of(Unit::file(Path::of('src/HeldToo.php'))))
            ->carrying(Units::of(Unit::file(Path::of('src/Gone.php')))),
    );
    $handoff = new Handoff(Directory::at(Scratch::directory()));
    $handoff->write($considered, $map, KillHistory::none());
    $handed = $handoff->forVerdict();

    expect($handed instanceof CoverageMap ? $handed->files() : $handed)->toEqual(Paths::of(
        Path::of('src/Money.php'),
        Path::of('src/Held/A.php'),
        Path::of('src/Held/B.php'),
        Path::of('src/HeldToo.php'),
    ))
        ->and($handed instanceof CoverageMap ? $handed->durationOf(TestId::of('MoneyTest::adds')) : $handed)
        ->toEqual(Seconds::of(0.5));
});

it('says the verdict was handed no map where there is none, and cannot read one that is not a map', function (): void {
    $project = Scratch::directory();
    $handoff = new Handoff(Directory::at($project));
    $missing = $handoff->forVerdict();
    Scratch::write($project, '.mutation-gate/coverage/verdict/map.json.gz', 'not a map');

    expect($missing)->toEqual(CannotJudge::because(
        'The verdict was handed no coverage map at .mutation-gate/coverage/verdict/map.json.gz. '
        . 'Hand it the plan\'s .mutation-gate/coverage.',
    ))
        ->and($handoff->forVerdict())->toBeInstanceOf(CannotJudge::class);
});

it('cannot hand the verdict a map it cannot write', function () use ($plan, $map): void {
    $project = Scratch::directory();
    Scratch::write($project, '.mutation-gate/coverage/verdict/map.json.gz/blocked', '');

    expect(new Handoff(Directory::at($project))->write($plan, $map, KillHistory::none()))
        ->toEqual(CannotJudge::because(
            sprintf('%s/.mutation-gate/coverage/verdict/map.json.gz could not be written.', $project),
        ));
});
