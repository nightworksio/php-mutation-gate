<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Pest\Interpretation;
use NightWorksIO\MutationGate\Adapter\Pest\ReplayVerdict;
use NightWorksIO\MutationGate\Cli\Flow\LeftoverCutting;
use NightWorksIO\MutationGate\Cli\Flow\LeftoverParts;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Shards as ShardSettings;
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
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShares;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Plan\StreamWall;
use NightWorksIO\MutationGate\Core\Proof\KillHistoryFile;
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

/**
 * The fixture's result with both mutants left at the deadline, Minus-21
 * moved to src/Other.php so each is a source file of its own, and
 * GreaterThan-16 spanning these lines, written where the shard leaves it.
 */
function leftoverCuttingApart(string $project, ShardResult $whole, int $last = 16): void
{
    $left = static fn(Mutant $mutant, string $file, int $start, int $end): Mutant => Mutant::of(
        $mutant->id(),
        $mutant->nativeId(),
        Location::of(Path::of($file), Line::of($start), Line::of($end)),
        $mutant->mutation(),
        $mutant->status(),
        $mutant->duration(),
    )->unjudged(OutOfTime::BeforeRunning);
    Scratch::write($project, sprintf('%s/1.json', Workspace::results()->value()), ShardResultFile::encode(
        LeftAtTheDeadline::resultOf($whole, static fn(Mutant $mutant): Mutant => match ($mutant->nativeId()) {
            'Minus-21' => $left($mutant, 'src/Other.php', 21, 21),
            'GreaterThan-16' => $left($mutant, 'src/Money.php', 16, $last),
            default => $mutant,
        }),
    ));
}

/**
 * The map, timing each test at its seconds and placing each on its file and line, written where the plan leaves it.
 *
 * @param array<string, array{float, string, int}> $tests by each test's id; a test of no file covers no line
 */
function leftoverCuttingMap(string $project, array $tests): void
{
    $map = CoverageMap::of();

    foreach ($tests as $test => [$seconds, $file, $line]) {
        $map = $map->timed(TestId::of($test), Seconds::of($seconds));
        $map = $file === '' ? $map : $map->covered(Path::of($file), Line::of($line), TestId::of($test));
    }

    Scratch::write(
        $project,
        '.mutation-gate/coverage/map.json.gz',
        CoverageMapFile::encode($map, MeasuredAt::of(Revision::ref(str_repeat('a', 40)), dirty: false)),
    );
}

it('cuts the mutants each shard left by the seconds the plan\'s map timed the test files covering them at, and writes the shares where they are read', function (array $tests, float $limit, array $expected): void {
    /** @var array<string, array{float, string, int}> $tests */
    /** @var list<list<string>> $expected */
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    $natives = [];

    foreach (LeftAtTheDeadline::mutantsOf($whole) as $mutant) {
        $natives[$mutant->id()->value()] = $mutant->nativeId();
    }

    leftoverCuttingApart($project, $whole);

    if ($tests !== []) {
        leftoverCuttingMap($project, $tests);
    }

    $cut = new LeftoverCutting(Flows::adapters($project))->cut($plan, Workspace::results(), Workspace::coverage(), ShardSettings::of(jobLimit: Seconds::of($limit)), StreamWall::of(1, 0.0));
    $written = LeftoverShares::decode((string) file_get_contents(sprintf('%s/%s', $project, LeftoverParts::file(Workspace::results())->value())));

    expect($cut instanceof LeftoverShares ? leftoverCutNatives($cut->ofShard(ShardId::of(1)), $natives) : $cut)->toBe($expected)
        ->and($cut instanceof LeftoverShares ? $cut->ofShard(ShardId::of(2)) : $cut)->toBe([])
        ->and($written)->toEqual($cut);
})->with([
    'timed by the map: a share each' => [
        ['MoneyTest::compares' => [50.0, 'src/Money.php', 16], 'OtherTest::subtracts' => [40.0, 'src/Other.php', 21]],
        60.0,
        [['GreaterThan-16'], ['Minus-21']],
    ],
    'no map: one share' => [[], 60.0, [['GreaterThan-16', 'Minus-21']]],
    'one test file covering both: one share' => [
        ['MoneyTest::compares' => [50.0, 'src/Money.php', 16], 'MoneyTest::subtracts' => [40.0, 'src/Other.php', 21]],
        100.0,
        [['GreaterThan-16', 'Minus-21']],
    ],
    'a file timed by every test in it, covering or not' => [
        ['MoneyTest::compares' => [50.0, 'src/Money.php', 16], 'MoneyTest::adds' => [30.0, '', 0], 'OtherTest::subtracts' => [40.0, 'src/Other.php', 21]],
        100.0,
        [['GreaterThan-16'], ['Minus-21']],
    ],
]);

it('prices a mutant over several lines by the test files covering any of them, to its last', function (int $last, int $shares): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    leftoverCuttingApart($project, $whole, $last);
    leftoverCuttingMap($project, ['MoneyTest::spans' => [70.0, 'src/Money.php', 17]]);

    $cut = new LeftoverCutting(Flows::adapters($project))->cut($plan, Workspace::results(), Workspace::coverage(), ShardSettings::of(jobLimit: Seconds::of(60.0)), StreamWall::of(1, 0.0));

    expect($cut instanceof LeftoverShares ? count($cut->ofShard(ShardId::of(1))) : $cut)->toBe($shares);
})->with([
    'to line 17: over the capacity' => [17, 2],
    'line 16 alone: uncovered' => [16, 1],
]);

it('gives a job of the second wave the limit less the time its shard took to start judging', function (float $opening, int $shares): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    leftoverCuttingApart($project, $whole);
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
    leftoverCuttingMap($project, ['MoneyTest::compares' => [50.0, 'src/Money.php', 16], 'OtherTest::subtracts' => [40.0, 'src/Other.php', 21]]);

    $cut = new LeftoverCutting(Flows::adapters($project))->cut($plan, Workspace::results(), Workspace::coverage(), ShardSettings::of(jobLimit: Seconds::of(100.0)), StreamWall::of(1, 0.0));

    expect($cut instanceof LeftoverShares ? count($cut->ofShard(ShardId::of(1))) : $cut)->toBe($shares);
})->with([
    'no opening: the whole limit' => [0.0, 1],
    'opened 30 s: 70 s left' => [30.0, 2],
    'opened past the limit: a share each' => [150.0, 2],
]);

it('prices a mutant its shard left unrun once its own runs began at what each finished own run took, at its covering tests\' seconds where none finished, and counts the share\'s time from when they began', function (Closure $passes, float $limit, int $shares): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    leftoverCuttingApart($project, $whole);
    $file = sprintf('%s/1.json', Workspace::results()->value());
    $stopped = ShardResultFile::decode((string) file_get_contents(sprintf('%s/%s', $project, $file)));
    $steps = StepTimes::of(
        StepTime::of(Step::Mutation, Seconds::of(0.0), Seconds::of(400.0)),
        ...array_filter(is_array($made = $passes()) ? $made : [], static fn(mixed $pass): bool => $pass instanceof StepTime),
    );
    Scratch::write($project, $file, $stopped instanceof ShardResult ? ShardResultFile::encode(ShardResult::of(
        $stopped->plan(),
        $stopped->shard(),
        $stopped->units(),
        $stopped->outcome(),
        $stopped->measured()->withSteps($steps),
    )) : '');
    leftoverCuttingMap($project, ['MoneyTest::compares' => [50.0, 'src/Money.php', 16], 'OtherTest::subtracts' => [40.0, 'src/Other.php', 21]]);

    $cut = new LeftoverCutting(Flows::adapters($project))->cut($plan, Workspace::results(), Workspace::coverage(), ShardSettings::of(jobLimit: Seconds::of($limit)), StreamWall::of(1, 0.0));

    expect($cut instanceof LeftoverShares ? count($cut->ofShard(ShardId::of(1))) : $cut)->toBe($shares);
})->with([
    'three of five own runs finished in 300 s: 100 s each, past a share' => [
        static fn(): array => [StepTime::of(Step::SecondPass, Seconds::of(0.0), Seconds::of(10.0)), StepTime::of(Step::OwnRuns, Seconds::of(10.0), Seconds::of(300.0), 5)],
        250.0,
        2,
    ],
    'the same, in a job long enough for both' => [
        static fn(): array => [StepTime::of(Step::SecondPass, Seconds::of(0.0), Seconds::of(10.0)), StepTime::of(Step::OwnRuns, Seconds::of(10.0), Seconds::of(300.0), 5)],
        300.0,
        1,
    ],
    'none of two finished: each at its covering tests, past a share' => [
        static fn(): array => [StepTime::of(Step::SecondPass, Seconds::of(0.0), Seconds::of(10.0)), StepTime::of(Step::OwnRuns, Seconds::of(10.0), Seconds::of(300.0), 2)],
        160.0,
        2,
    ],
    'none of two finished, in a job long enough for both' => [
        static fn(): array => [StepTime::of(Step::SecondPass, Seconds::of(0.0), Seconds::of(10.0)), StepTime::of(Step::OwnRuns, Seconds::of(10.0), Seconds::of(300.0), 2)],
        190.0,
        1,
    ],
    'stopped in its second pass: no own run began, priced by its streams' => [
        static fn(): array => [StepTime::of(Step::SecondPass, Seconds::of(0.0), Seconds::of(400.0))],
        160.0,
        1,
    ],
    'own runs began 140 s in: 170 s left of 310' => [
        static fn(): array => [StepTime::of(Step::SecondPass, Seconds::of(0.0), Seconds::of(140.0)), StepTime::of(Step::OwnRuns, Seconds::of(140.0), Seconds::of(260.0), 2)],
        310.0,
        2,
    ],
    'own runs began 120 s in: 190 s left of 310' => [
        static fn(): array => [StepTime::of(Step::SecondPass, Seconds::of(0.0), Seconds::of(120.0)), StepTime::of(Step::OwnRuns, Seconds::of(120.0), Seconds::of(280.0), 2)],
        310.0,
        1,
    ],
]);

it('prices a left mutant\'s first fork at the likely killer its shard\'s handed ledger names among its covering tests, and its second pass at every covering test, named or not', function (bool $named, float $passTwo, float $limit, int $shares): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    leftoverCuttingApart($project, $whole);
    leftoverCuttingMap($project, [
        'MoneyTest::compares' => [50.0, 'src/Money.php', 16],
        'MoneyTest::checks' => [10.0, 'src/Money.php', 16],
        'OtherTest::subtracts' => [40.0, 'src/Other.php', 21],
    ]);
    $history = KillHistory::none();

    foreach (LeftAtTheDeadline::mutantsOf($whole) as $mutant) {
        $history = $named && $mutant->nativeId() === 'GreaterThan-16'
            ? $history->withMutant($mutant->id(), Ranking::none()->killedBy(TestId::of('MoneyTest::checks')))
            : $history;
    }

    Scratch::write($project, '.mutation-gate/coverage/shard-1/killers.json', KillHistoryFile::encode($history));

    $cut = new LeftoverCutting(Flows::adapters($project))->cut($plan, Workspace::results(), Workspace::coverage(), ShardSettings::of(jobLimit: Seconds::of($limit)), StreamWall::of(1, $passTwo));

    expect($cut instanceof LeftoverShares ? count($cut->ofShard(ShardId::of(1))) : $cut)->toBe($shares);
})->with([
    'a killer named: its fork besides the files, a share each' => [true, 0.0, 105.0, 2],
    'none named: the files alone, one share' => [false, 0.0, 105.0, 1],
    'none named, a second pass at every covering test: a share each' => [false, 1.0, 105.0, 2],
    'a killer named, a second pass at every covering test still: a share each' => [true, 1.0, 160.0, 2],
    'a killer named, the whole of both passes in one job: one share' => [true, 1.0, 210.0, 1],
]);

it('cuts no share of any shard where one left no result, saying why, and cuts the left mutants where every shard left one', function (): void {
    [$project, $plan] = LeftAtTheDeadline::run();
    $cut = static fn(): LeftoverShares|CannotJudge => new LeftoverCutting(Flows::adapters($project))->cut($plan, Workspace::results(), Workspace::coverage(), ShardSettings::of(jobLimit: Seconds::of(600.0)), StreamWall::of(1));
    $read = $cut();
    unlink(sprintf('%s/%s', $project, Workspace::result(Workspace::results(), ShardId::of(2))->value()));
    $decided = $cut();

    expect($read instanceof LeftoverShares ? [$read->decidedBy(), $read->ofShard(ShardId::of(1)) !== []] : $read)->toEqual([NotGiven::value(), true])
        ->and($decided instanceof LeftoverShares ? [$decided->matrix(), is_string($decided->decidedBy())] : $decided)->toBe([[], true]);
});

it('cuts by an empty map where the plan left none it can read', function (): void {
    [$project, $plan] = LeftAtTheDeadline::run();
    $map = sprintf('%s/.mutation-gate/coverage/map.json.gz', $project);
    is_file($map) ? unlink($map) : null;
    $cut = static fn(): LeftoverShares|CannotJudge => new LeftoverCutting(Flows::adapters($project))->cut($plan, Workspace::results(), Workspace::coverage(), ShardSettings::of(jobLimit: Seconds::of(600.0)), StreamWall::of(1));
    $unmapped = $cut();
    leftoverCuttingMap($project, []);
    $empty = $cut();

    expect($unmapped instanceof LeftoverShares ? $unmapped->encode() : $unmapped)
        ->toBe($empty instanceof LeftoverShares ? $empty->encode() : $empty)
        ->and($unmapped instanceof LeftoverShares ? $unmapped->ofShard(ShardId::of(1)) : [])->not->toBe([]);
});

it('hands on each kill its shard ran out of time to vouch for, which a later job judges as one job judging every mutant would, and no mutant its shard left unjudged for another reason', function (): void {
    [$project, $plan, $whole] = LeftAtTheDeadline::run();
    $file = sprintf('%s/1.json', Workspace::results()->value());
    $left = [
        'Plus-11' => ReplayVerdict::NoTime->reason(),
        'GreaterThan-16' => OutOfTime::BeforeVouching->reason(),
        'Decrement-27' => ReplayVerdict::Failed->reason(),
    ];
    Scratch::write($project, $file, ShardResultFile::encode(LeftAtTheDeadline::resultOf(
        $whole,
        static fn(Mutant $mutant): Mutant => array_key_exists($mutant->nativeId(), $left) && $left[$mutant->nativeId()] instanceof Reason
            ? Interpretation::unjudged($mutant, $left[$mutant->nativeId()])
            : $mutant,
    )));

    $cut = new LeftoverCutting(Flows::adapters($project))->cut($plan, Workspace::results(), Workspace::coverage(), ShardSettings::of(jobLimit: Seconds::of(600.0)), StreamWall::of(1));
    $shares = $cut instanceof LeftoverShares ? $cut->ofShard(ShardId::of(1)) : [];
    $handed = [];

    foreach ($shares as $share) {
        $ids = array_map(static fn(MutantId $id): string => $id->value(), [...$share->mutants()]);
        $handed = [...$handed, ...$ids];
        Scratch::write($project, LeftoverParts::resultOf(Workspace::results(), $share)->value(), ShardResultFile::encode(LeftAtTheDeadline::resultOf(
            $whole,
            static fn(Mutant $mutant): Mutant|false => in_array($mutant->id()->value(), $ids, strict: true) ? $mutant : false,
        )));
    }

    $judged = Results::read($plan, Workspace::results(), Directory::at($project));
    $statuses = [];

    foreach ($judged instanceof Results ? $judged->units() : [] as $unit) {
        foreach ($unit->mutants() as $mutant) {
            $statuses[$mutant->id()->value()] = $mutant->status();
        }
    }

    $expected = [];

    foreach (LeftAtTheDeadline::mutantsOf($whole) as $mutant) {
        $expected[$mutant->id()->value()] = $mutant->nativeId() === 'Decrement-27' ? MutantStatus::Unjudged : $mutant->status();
    }

    expect($handed)->toEqualCanonicalizing(array_map(static fn(MutantId $id): string => $id->value(), [...LeftAtTheDeadline::ids($whole, 'Plus-11', 'GreaterThan-16')]))
        ->and(array_intersect_key($statuses, $expected))->toEqual($expected)
        ->and(array_count_values(array_map(static fn(MutantStatus $status): string => $status->value, $expected)))
        ->toMatchArray(['killed' => 1, 'survived' => 1, 'unjudged' => 1]);
});
