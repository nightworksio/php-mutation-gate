<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\LeftoverCutting;
use NightWorksIO\MutationGate\Cli\Flow\LeftoverParts;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShares;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\LeftAtTheDeadline;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The shares as native ids, each share's in its order.
 *
 * @param  list<LeftoverShare>  $shares
 * @param  array<string, string> $natives each mutant's native id, by its id
 * @return list<list<string>>
 */
function leftoverCutNatives(array $shares, array $natives): array
{
    return array_map(
        static fn(LeftoverShare $share): array => array_map(
            static fn(MutantId $id): string => $natives[$id->value()],
            [...$share->mutants()],
        ),
        $shares,
    );
}

it('cuts the mutants each shard left by the seconds the plan\'s map timed their covering tests at, and writes the shares where they are read', function (bool $mapped, array $expected): void {
    /** @var list<list<string>> $expected */
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    $natives = [];

    foreach (LeftAtTheDeadline::mutantsOf($whole) as $mutant) {
        $natives[$mutant->id()->value()] = $mutant->nativeId();
    }

    if ($mapped) {
        $map = CoverageMap::of()
            ->covered(Path::of('src/Money.php'), Line::of(16), TestId::of('MoneyTest::compares'))
            ->covered(Path::of('src/Money.php'), Line::of(21), TestId::of('MoneyTest::subtracts'))
            ->timed(TestId::of('MoneyTest::compares'), Seconds::of(50.0))
            ->timed(TestId::of('MoneyTest::subtracts'), Seconds::of(40.0));
        Scratch::write(
            $project,
            '.mutation-gate/coverage/map.json.gz',
            CoverageMapFile::encode($map, MeasuredAt::of(Revision::ref(str_repeat('a', 40)), dirty: false)),
        );
    }

    $cut = new LeftoverCutting(Flows::adapters($project))->cut($plan, Workspace::results(), Workspace::coverage(), Seconds::of(60.0));
    $written = LeftoverShares::decode((string) file_get_contents(sprintf('%s/%s', $project, LeftoverParts::file(Workspace::results())->value())));

    expect($cut instanceof LeftoverShares ? leftoverCutNatives($cut->ofShard(ShardId::of(1)), $natives) : $cut)->toBe($expected)
        ->and($cut instanceof LeftoverShares ? $cut->ofShard(ShardId::of(2)) : $cut)->toBe([])
        ->and($written)->toEqual($cut);
})->with([
    'timed by the map: a share each' => [true, [['GreaterThan-16'], ['Minus-21']]],
    'no map: one share' => [false, [['GreaterThan-16', 'Minus-21']]],
]);

it('predicts a mutant over several lines by the tests covering any of them, to its last', function (): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    $spanning = static fn(Mutant $mutant): Mutant => $mutant->nativeId() === 'GreaterThan-16'
        ? Mutant::of(
            $mutant->id(),
            $mutant->nativeId(),
            Location::of(Path::of('src/Money.php'), Line::of(16), Line::of(17)),
            $mutant->mutation(),
            $mutant->status(),
            $mutant->duration(),
        )->unjudged(OutOfTime::BeforeRunning)
        : $mutant;
    $stopped = LeftAtTheDeadline::resultOf($whole, $spanning);
    Scratch::write($project, sprintf('%s/1.json', Workspace::results()->value()), ShardResultFile::encode(
        LeftAtTheDeadline::resultOf($stopped, static fn(Mutant $mutant): Mutant => $mutant->nativeId() === 'Minus-21'
            ? $mutant->unjudged(OutOfTime::BeforeRunning)
            : $mutant),
    ));
    $map = CoverageMap::of()
        ->covered(Path::of('src/Money.php'), Line::of(17), TestId::of('MoneyTest::spans'))
        ->timed(TestId::of('MoneyTest::spans'), Seconds::of(70.0));
    Scratch::write(
        $project,
        '.mutation-gate/coverage/map.json.gz',
        CoverageMapFile::encode($map, MeasuredAt::of(Revision::ref(str_repeat('a', 40)), dirty: false)),
    );

    $cut = new LeftoverCutting(Flows::adapters($project))->cut($plan, Workspace::results(), Workspace::coverage(), Seconds::of(60.0));

    expect($cut instanceof LeftoverShares ? count($cut->ofShard(ShardId::of(1))) : $cut)->toBe(2);
});

it('gives a job of the second wave the limit less the time its shard took to start judging', function (float $opening, int $shares): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    $file = sprintf('%s/1.json', Workspace::results()->value());
    $stopped = ShardResultFile::decode((string) file_get_contents(sprintf('%s/%s', $project, $file)));
    $steps = $opening > 0.0
        ? StepTimes::of(StepTime::of(Step::Coverage, Seconds::of(0.0), Seconds::of($opening)), StepTime::of(Step::Mutation, Seconds::of($opening), Seconds::of(5.0)))
        : StepTimes::none();
    Scratch::write($project, $file, $stopped instanceof ShardResult ? ShardResultFile::encode(ShardResult::of(
        $stopped->plan(),
        $stopped->shard(),
        $stopped->units(),
        $stopped->outcome(),
        $stopped->measured()->withSteps($steps),
    )) : '');
    $map = CoverageMap::of()
        ->covered(Path::of('src/Money.php'), Line::of(16), TestId::of('MoneyTest::compares'))
        ->covered(Path::of('src/Money.php'), Line::of(21), TestId::of('MoneyTest::subtracts'))
        ->timed(TestId::of('MoneyTest::compares'), Seconds::of(50.0))
        ->timed(TestId::of('MoneyTest::subtracts'), Seconds::of(40.0));
    Scratch::write(
        $project,
        '.mutation-gate/coverage/map.json.gz',
        CoverageMapFile::encode($map, MeasuredAt::of(Revision::ref(str_repeat('a', 40)), dirty: false)),
    );

    $cut = new LeftoverCutting(Flows::adapters($project))->cut($plan, Workspace::results(), Workspace::coverage(), Seconds::of(100.0));

    expect($cut instanceof LeftoverShares ? count($cut->ofShard(ShardId::of(1))) : $cut)->toBe($shares);
})->with([
    'no opening: the whole limit' => [0.0, 1],
    'opened 30 s: 70 s left' => [30.0, 2],
    'opened past the limit: a share each' => [150.0, 2],
]);
