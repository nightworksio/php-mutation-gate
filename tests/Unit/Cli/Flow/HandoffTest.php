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
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
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
    $written = $handoff->write($plan, $map);
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
    new Handoff(Directory::at($project))->write($plan, $map);

    expect(file_exists(sprintf('%s/.mutation-gate/coverage/shard-1/map.json.gz', $project)))->toBeTrue()
        ->and(file_exists(sprintf('%s/.mutation-gate/coverage/shard-2/map.json.gz', $project)))->toBeTrue();
});

it('cannot hand a shard a map it cannot write', function () use ($plan, $map): void {
    $project = Scratch::directory();
    Scratch::write($project, '.mutation-gate/coverage/shard-2/map.json.gz/blocked', '');

    expect(new Handoff(Directory::at($project))->write($plan, $map))->toEqual(CannotJudge::because(
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
