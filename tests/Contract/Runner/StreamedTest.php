<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\PestSteps;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPace;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSettings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\Config\FirstFork;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Config\NarrowedKillTrust;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Config\Toggles;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Arithmetic\MultiplicationToDivision;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;
use Pest\Mutate\Mutators\Arithmetic\PostIncrementToPostDecrement;

$holds = [
    'holds:src/Adapter/Pest',
];

/** The fixture's second killer of a change to Money::add, which a likely killer's stream runs alone. */
const STREAMED_TWICE = 'P\\Tests\\MoneySpec::__pest_evaluable_it_adds_the_same_amount_to_itself';

/** The fixture's test of Money::isLarge, which covers its boundary without telling it apart. */
const STREAMED_TELLS = 'P\\Tests\\MoneySpec::__pest_evaluable_it_tells_a_large_amount_from_a_small_one';

/** The fixture's slow covering test of Cheapest, first in the suite's order. */
const CHEAPEST_SLOW = 'P\\Tests\\CheapestSpec::__pest_evaluable_it_doubles_an_amount__triples_nothing_and_quadruples_one__slowly';

/** The fixture's fast covering test of Cheapest. */
const CHEAPEST_FAST = 'P\\Tests\\CheapestSpec::__pest_evaluable_it_doubles_and_triples_into_whole_numbers__and_quadruples_two';

/** The fixture's test of Booted, which the application's boot readies. */
const BOOTED_KILLER = 'P\\Tests\\BootedSpec::__pest_evaluable_it_readies_the_services';

/** The fixture's likely killer of a change to Fallen::tripled, which holds a writable file, so no stream forks there. */
const FALLEN_KILLER = 'P\\Tests\\FallenSpec::__pest_evaluable_it_triples_while_it_holds_a_writable_file';

/**
 * Each mutant of a run by the line it is on: its status, its killers and whether a stream judged it; why the run
 * judged none, where it did not.
 *
 * @return array<int, array{MutantStatus, list<string>, bool}>|string
 */
function streamedJudged(MutationResult|CannotJudge $result): array|string
{
    if (! $result instanceof MutationResult) {
        return $result->why();
    }

    $judged = [];

    foreach ($result->mutants() as $mutant) {
        $judged[$mutant->location()->start()->number()] = [
            $mutant->status(),
            array_map(static fn(TestId $killer): string => $killer->value(), [...$mutant->killers()]),
            $result->evidence()->of($mutant->id())->isStreamed(),
        ];
    }

    return $judged;
}

/**
 * The sites each of a run's two stream passes took, in the order the run timed them; none where it judged none.
 *
 * @return list<array{Step, int}>
 */
function streamedPasses(MutationResult|CannotJudge $result): array
{
    if (! $result instanceof MutationResult) {
        return [];
    }

    $passes = array_filter(
        [...$result->steps()],
        static fn(StepTime $step): bool => $step->step() === Step::FirstPass || $step->step() === Step::SecondPass,
    );

    return array_map(static fn(StepTime $step): array => [$step->step(), $step->count()], array_values($passes));
}

it('kills in its stream a mutant a test tells apart, or whose test ends its own process, stops at its limit one that never ends, and runs on its own one only a test holding a socket, a writable file or a file-backed database reaches', function (): void {
    $source = Tree::at(sprintf('%s/src/Streamed.php', Library::DIRECTORY));
    $test = Tree::at(sprintf('%s/tests/StreamedSpec.php', Library::DIRECTORY));
    copy(Tree::at('tests/Contract/Runner/streamed/src/Streamed.php'), $source);
    copy(Tree::at('tests/Contract/Runner/streamed/tests/StreamedSpec.php'), $test);
    Library::patched();
    $own = Scratch::directory();
    $held = [getenv('TMPDIR'), array_key_exists('TMPDIR', $_SERVER) ? $_SERVER['TMPDIR'] : NotGiven::value()];
    putenv(sprintf('TMPDIR=%s', $own));
    $_SERVER['TMPDIR'] = $own;

    try {
        $result = Library::pest(Patching::on(Library::canary())->splitting(
            StreamSettings::of(enabled: true, children: 1, changeCheck: false, killersFirst: false),
        ))->runner()->mutate(
            MutationRequest::of(Paths::of(Path::of('src/Streamed.php')), WholeSuite::tests())->narrowedTo(
                Paths::of(Path::of('src/Streamed.php')),
                Narrowing::none()->toMutators(Mutators::named(
                    PlusToMinus::class,
                    PostIncrementToPostDecrement::class,
                    MultiplicationToDivision::class,
                )),
            ),
        );
    } finally {
        unlink($source);
        unlink($test);
        putenv($held[0] === false ? 'TMPDIR' : sprintf('TMPDIR=%s', $held[0]));
        unset($_SERVER['TMPDIR']);

        if (! $held[1] instanceof NotGiven) {
            $_SERVER['TMPDIR'] = $held[1];
        }
    }

    $judged = array_map(static fn(Mutant $mutant): array => [
        $mutant->location()->start()->number(),
        $mutant->status(),
        $mutant->status() === MutantStatus::Killed
            ? array_map(static fn(TestId $killer): string => $killer->value(), [...$mutant->killers()])
            : [],
        $result instanceof MutationResult && $result->evidence()->of($mutant->id())->isStreamed(),
    ], $result instanceof MutationResult ? [...$result->mutants()] : []);
    usort($judged, static fn(array $one, array $other): int => $one[0] <=> $other[0]);
    $seesClients = is_dir('/proc/self/fd') || function_exists('outbreak_descriptors');
    $steps = [];

    foreach ($result instanceof MutationResult ? $result->steps() : [] as $step) {
        $steps[$step->step()->value] = [$step->count(), $step->took()->seconds() < 600.0];
    }

    $beside = Tree::at(sprintf('%s/.mutation-gate/pest/results.jsonl', Library::DIRECTORY));
    $listed = sprintf('%s.passes', $beside);
    preg_match_all('~"begun":"([a-z ]+)"~', is_file($listed) ? sprintf('%s', file_get_contents($listed)) : '', $begun);

    expect(array_intersect_key($steps, ['first pass' => 0, 'second pass' => 0, 'own runs' => 0]))->toBe([
        'first pass' => [7, true],
        'second pass' => [0, true],
        'own runs' => [3, true],
    ])
        ->and($begun[1])->toBe(['first pass', 'second pass', 'own runs'])
        ->and(is_file(sprintf('%s.streams', $beside)))->toBeFalse()
        ->and(glob(sprintf('%s/mutation-gate-split-*', $own)))->toBe([])
        ->and($judged)->toBe([
            [17, MutantStatus::Killed, ['P\\Tests\\StreamedSpec::__pest_evaluable_it_adds'], true],
            [24, MutantStatus::TimedOut, [], true],
            [33, MutantStatus::Killed, ['P\\Tests\\StreamedSpec::__pest_evaluable_it_doubles_while_it_holds_a_socket'], false],
            [38, MutantStatus::Killed, ['P\\Tests\\StreamedSpec::__pest_evaluable_it_triples_while_it_holds_a_writable_file'], false],
            [43, MutantStatus::Killed, ['P\\Tests\\StreamedSpec::__pest_evaluable_it_quadruples_while_it_holds_a_file_backed_SQLite_database'], ! $seesClients],
            [48, MutantStatus::Killed, ['P\\Tests\\StreamedSpec::__pest_evaluable_it_shifts_while_it_reads_a_file'], true],
            [53, MutantStatus::Killed, ['P\\Tests\\StreamedSpec::__pest_evaluable_it_raises__ending_its_own_process_where_it_raises_wrong'], true],
        ]);
})->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and split streams need a PHP that forks',
)->group(...$holds);

it('forks a first pass at each likely killer alone, and a second at every covering test for a mutant its likely killer leaves undecided', function (): void {
    Library::patched();
    $library = Library::pest(Patching::on(Library::canary())->splitting(
        StreamSettings::of(enabled: true, children: 1, changeCheck: true, killersFirst: true, killerStreams: true),
    ));
    $history = KillHistory::none();

    foreach (['adds' => STREAMED_TWICE, 'large' => STREAMED_TELLS] as $change => $killer) {
        $id = MutantId::parse($library->expected($change)[0][0]);
        $history = $id instanceof MutantId ? $history->withMutant($id, Ranking::of(Kills::of(TestId::of($killer), 3))) : $history;
    }

    $result = $library->runner()->mutate(
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
            ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds', 'large')))
            ->searching(KillSearch::of(Ordering::of(TestOrder::KillersFirst, $history), MatrixKind::FirstKiller)),
    );
    expect(streamedJudged($result))->toBe([
        11 => [MutantStatus::Killed, [STREAMED_TWICE], true],
        16 => [MutantStatus::Survived, [], true],
    ])
        ->and(streamedPasses($result))->toBe([[Step::FirstPass, 2], [Step::SecondPass, 1]]);
})->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and split streams need a PHP that forks',
)->group(...$holds);

it('leaves a mutant whose likely killer the first pass fell back on to its own run, killer first, under splitStream.fallbackOwnRun, and streams it again otherwise', function (bool $ownRun, array $judged): void {
    $copied = [
        'src/Fallen.php' => 'tests/Contract/Runner/streamed/src/Fallen.php',
        'tests/FallenSpec.php' => 'tests/Contract/Runner/streamed/tests/FallenSpec.php',
    ];

    foreach ($copied as $into => $from) {
        copy(Tree::at($from), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
    }

    Library::patched();
    $request = MutationRequest::of(Paths::of(Path::of('src/Fallen.php')), WholeSuite::tests())->narrowedTo(
        Paths::of(Path::of('src/Fallen.php')),
        Narrowing::none()->toMutators(Mutators::named(MultiplicationToDivision::class)),
    );
    $streams = static fn(bool $killersFirst): StreamSettings => StreamSettings::of(
        enabled: true,
        children: 1,
        changeCheck: false,
        killersFirst: $killersFirst,
        fallbackOwnRun: $ownRun,
    );

    try {
        $first = Library::pest(Patching::on(Library::canary())->splitting($streams(killersFirst: false)))->runner()->mutate($request);
        $history = KillHistory::none();

        foreach ($first instanceof MutationResult ? $first->mutants() : [] as $mutant) {
            $history = $history->withMutant($mutant->id(), Ranking::of(Kills::of(TestId::of(FALLEN_KILLER), 3)));
        }

        $result = Library::pest(Patching::on(Library::canary())->splitting($streams(killersFirst: true)))->runner()->mutate(
            $request->searching(KillSearch::of(Ordering::of(TestOrder::KillersFirst, $history), MatrixKind::FirstKiller)),
        );
    } finally {
        foreach (array_keys($copied) as $into) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
        }
    }

    expect(streamedJudged($result))->toBe($judged);
})->with([
    'own run' => [true, [15 => [MutantStatus::Killed, [FALLEN_KILLER], false]]],
    'second pass' => [false, [15 => [MutantStatus::Killed, ['P\\Tests\\FallenSpec::__pest_evaluable_it_triples_a_small_amount'], true]]],
])->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and split streams need a PHP that forks',
)->group(...$holds);

it('judges a mutant alike whether its stream runs only the covering tests or their whole files, and in its stream only where it runs the covering tests alone, under splitStream.coveringTestsOnly', function (bool $coveringOnly): void {
    $copied = [
        'src/Selected.php' => 'tests/Contract/Runner/streamed/src/Selected.php',
        'tests/SelectedSpec.php' => 'tests/Contract/Runner/streamed/tests/SelectedSpec.php',
    ];

    foreach ($copied as $into => $from) {
        copy(Tree::at($from), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
    }

    Library::patched();

    try {
        $result = Library::pest(Patching::on(Library::canary())->splitting(StreamSettings::of(
            enabled: true,
            children: 1,
            changeCheck: false,
            killersFirst: false,
            coveringOnly: $coveringOnly,
        )))->runner()->mutate(
            MutationRequest::of(Paths::of(Path::of('src/Selected.php')), WholeSuite::tests())->narrowedTo(
                Paths::of(Path::of('src/Selected.php')),
                Narrowing::none()->toMutators(Mutators::named(MultiplicationToDivision::class)),
            ),
        );
    } finally {
        foreach (array_keys($copied) as $into) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
        }
    }

    expect(streamedJudged($result))->toBe([
        16 => [MutantStatus::Killed, ['P\\Tests\\SelectedSpec::__pest_evaluable_it_quadruples_a_small_amount'], $coveringOnly],
    ]);
})->with([
    'covering tests only' => [true],
    'whole files' => [false],
])->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and split streams need a PHP that forks',
)->group(...$holds);

it('judges a mutant no ledger names a killer of alike whether its first pass forks it at its cheapest covering test or at every one, naming the cheapest of its killers first, under splitStream.firstFork', function (FirstFork $fork, string $killer): void {
    $copied = [
        'src/Cheapest.php' => 'tests/Contract/Runner/streamed/src/Cheapest.php',
        'tests/CheapestSpec.php' => 'tests/Contract/Runner/streamed/tests/CheapestSpec.php',
    ];

    foreach ($copied as $into => $from) {
        copy(Tree::at($from), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
    }

    Library::patched();

    try {
        $result = Library::pest(Patching::on(Library::canary())->splitting(StreamSettings::of(
            enabled: true,
            children: 1,
            changeCheck: false,
            killersFirst: true,
            pace: new StreamPace(on: true, firstFork: $fork),
        )))->runner()->mutate(
            MutationRequest::of(Paths::of(Path::of('src/Cheapest.php')), WholeSuite::tests())->narrowedTo(
                Paths::of(Path::of('src/Cheapest.php')),
                Narrowing::none()->toMutators(Mutators::named(MultiplicationToDivision::class)),
            ),
        );
    } finally {
        foreach (array_keys($copied) as $into) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
        }
    }

    expect(streamedJudged($result))->toBe([
        17 => [MutantStatus::Killed, [CHEAPEST_SLOW], true],
        22 => [MutantStatus::Survived, [], true],
        27 => [MutantStatus::Killed, [$killer], true],
    ]);
})->with([
    'cheapest first' => [FirstFork::Cheapest, CHEAPEST_FAST],
    'every test' => [FirstFork::Every, CHEAPEST_SLOW],
])->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and split streams need a PHP that forks',
)->group(...$holds);

it('stops a stream whose parent runs a test past its deadline, and judges the mutant by the tests left in a stream started in its place, under splitStream.parentDeadline', function (): void {
    $copied = [
        'src/Stalled.php' => 'tests/Contract/Runner/streamed/src/Stalled.php',
        'tests/StalledSpec.php' => 'tests/Contract/Runner/streamed/tests/StalledSpec.php',
    ];

    foreach ($copied as $into => $from) {
        copy(Tree::at($from), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
    }

    Library::patched();
    $started = microtime(as_float: true);

    try {
        $result = Library::pest(Patching::on(Library::canary())->splitting(StreamSettings::of(
            enabled: true,
            children: 1,
            changeCheck: false,
            killersFirst: false,
        )))->runner()->mutate(
            MutationRequest::of(Paths::of(Path::of('src/Stalled.php')), WholeSuite::tests())->narrowedTo(
                Paths::of(Path::of('src/Stalled.php')),
                Narrowing::none()->toMutators(Mutators::named(PlusToMinus::class)),
            ),
        );
    } finally {
        foreach (array_keys($copied) as $into) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
        }
    }

    expect([streamedJudged($result), microtime(as_float: true) - $started < 60.0])->toBe([
        [16 => [MutantStatus::Killed, ['P\\Tests\\StalledSpec::__pest_evaluable_it_raises_a_small_amount'], true]],
        true,
    ]);
})->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and split streams need a PHP that forks',
)->group(...$holds);

it('judges a mutant whose test reads its coverage through pcov alike whether pest.pcovOff turns pcov off in the run or not, its stream keeping pcov on', function (bool $pcovOff): void {
    $copied = [
        'src/Measured.php' => 'tests/Contract/Runner/streamed/src/Measured.php',
        'tests/MeasuredSpec.php' => 'tests/Contract/Runner/streamed/tests/MeasuredSpec.php',
    ];

    foreach ($copied as $into => $from) {
        copy(Tree::at($from), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
    }

    Library::patched();

    try {
        $result = Library::pest(Patching::on(Library::canary())
            ->stepping(PestSteps::of(Toggles::of(Toggle::PestPcovOff, $pcovOff), NarrowedKillTrust::Vouch, LongFilter::DEFAULT, Duplicates::JudgeOnce))
            ->splitting(StreamSettings::of(enabled: true, children: 1, changeCheck: false, killersFirst: false)))
            ->runner()->mutate(
                MutationRequest::of(Paths::of(Path::of('src/Measured.php')), WholeSuite::tests())->narrowedTo(
                    Paths::of(Path::of('src/Measured.php')),
                    Narrowing::none()->toMutators(Mutators::named(PlusToMinus::class)),
                ),
            );
    } finally {
        foreach (array_keys($copied) as $into) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
        }
    }

    expect(streamedJudged($result))->toBe([
        15 => [MutantStatus::Killed, ['P\\Tests\\MeasuredSpec::__pest_evaluable_it_doubles_a_small_amount_where_pcov_is_on'], true],
    ]);
})->with([
    'pcov off in the run' => [true],
    'pcov as PHP is configured' => [false],
])->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('pcov\\start') || ini_get('pcov.enabled') !== '1',
    'the runner contracts job installs the fixture library, split streams need a PHP that forks, and the test needs pcov on',
)->group(...$holds);

it('leaves a mutant whose site the application\'s boot runs to a second pass that boots in every test, whether its first pass runs every covering file or its likely killer alone, under splitStream.bootOnce', function (bool $killers): void {
    $copied = [
        'src/Booted.php' => 'tests/Contract/Runner/streamed/src/Booted.php',
        'tests/BootedSpec.php' => 'tests/Contract/Runner/streamed/tests/BootedSpec.php',
        'bootstrap/app.php' => 'tests/Contract/Runner/streamed/bootstrap/app.php',
    ];
    $bootstrap = Tree::at(sprintf('%s/bootstrap', Library::DIRECTORY));
    mkdir($bootstrap);

    foreach ($copied as $into => $from) {
        copy(Tree::at($from), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
    }

    Library::patched();
    $request = MutationRequest::of(Paths::of(Path::of('src/Booted.php')), WholeSuite::tests())->narrowedTo(
        Paths::of(Path::of('src/Booted.php')),
        Narrowing::none()->toMutators(Mutators::named(PlusToMinus::class)),
    );
    $streams = static fn(bool $killers): StreamSettings => StreamSettings::of(
        enabled: true,
        children: 1,
        changeCheck: false,
        killersFirst: $killers,
        killerStreams: $killers,
        bootOnce: true,
    );

    try {
        $result = Library::pest(Patching::on(Library::canary())->splitting($streams(killers: false)))->runner()->mutate($request);
        $history = KillHistory::none();

        foreach ($killers && $result instanceof MutationResult ? $result->mutants() : [] as $mutant) {
            $history = $history->withMutant($mutant->id(), Ranking::of(Kills::of(TestId::of(BOOTED_KILLER), 3)));
        }

        $result = $killers
            ? Library::pest(Patching::on(Library::canary())->splitting($streams(killers: true)))->runner()->mutate(
                $request->searching(KillSearch::of(Ordering::of(TestOrder::KillersFirst, $history), MatrixKind::FirstKiller)),
            )
            : $result;
    } finally {
        foreach (array_keys($copied) as $into) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
        }

        rmdir($bootstrap);
    }

    expect(streamedJudged($result))->toBe([12 => [MutantStatus::Killed, [BOOTED_KILLER], true]])
        ->and(streamedPasses($result))->toBe([[Step::FirstPass, 1], [Step::SecondPass, 1]]);
})->with([
    'every covering file' => [false],
    'its likely killer alone' => [true],
])->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and split streams need a PHP that forks',
)->group(...$holds);

it('judges by its own run a mutant a test reaches only in a PHP process it started, or whose site it reaches after one, which ran without the mutant', function (string $spec, string $test, bool $handed): void {
    $files = [
        'src/Spawned.php' => 'tests/Contract/Runner/spawned/src/Spawned.php',
        sprintf('tests/%s.php', $spec) => sprintf('tests/Contract/Runner/spawned/tests/%s.php', $spec),
        'spawned.php' => 'tests/Contract/Runner/spawned/spawned.php',
    ];
    $killer = sprintf('P\\Tests\\%s::__pest_evaluable_it_%s', $spec, $test);

    foreach ($files as $placed => $shipped) {
        copy(Tree::at($shipped), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $placed)));
    }

    Library::patched();
    $library = Library::pestPlacing(Patching::on(Library::canary())->splitting(
        StreamSettings::of(enabled: true, children: 1, changeCheck: false, killersFirst: false),
    ));
    $request = MutationRequest::of(Paths::of(Path::of('src/Spawned.php')), WholeSuite::tests())->narrowedTo(
        Paths::of(Path::of('src/Spawned.php')),
        Narrowing::none()->toMutators(Mutators::named(PlusToMinus::class)),
    );

    try {
        if ($handed) {
            $handing = Path::of('.mutation-gate/spawned');
            $measured = $library->runner()->coverage(CoverageRun::of(WholeSuite::tests(), $handing));
            file_put_contents(
                Tree::at(sprintf('%s/%s', Library::DIRECTORY, CoverageMapFile::in($handing)->value())),
                CoverageMapFile::encode(
                    ($measured instanceof CoverageMap ? $measured : CoverageMap::of())->covered(Path::of('src/Spawned.php'), Line::of(15), TestId::of($killer)),
                    Unplaced::map(),
                ),
            );
            $request = $request->reusingCoverage(Handed::maps($handing, $handing));
        }

        $result = $library->runner()->mutate($request);
    } finally {
        foreach (array_keys($files) as $placed) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $placed)));
        }
    }

    expect(streamedJudged($result))->toBe([15 => [MutantStatus::Killed, [$killer], false]]);
})->with([
    'reached only in the process it started' => ['SpawnedOnlySpec', 'adds_two_numbers_in_a_process_of_its_own', true],
    'reached after the process it started' => ['SpawnedSpec', 'adds_zeros_here_and_two_numbers_in_a_process_of_its_own', false],
])->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and split streams need a PHP that forks',
)->group(...$holds);

it('gives a mutant the first pass settled its verdict before the second pass starts, so a run its deadline stops in the second judges it as a whole run does, under splitStream.earlyVerdicts', function (): void {
    $copied = [
        'src/Settled.php' => 'tests/Contract/Runner/streamed/src/Settled.php',
        'src/Waited.php' => 'tests/Contract/Runner/streamed/src/Waited.php',
        'tests/SettledSpec.php' => 'tests/Contract/Runner/streamed/tests/SettledSpec.php',
        'tests/WaitedSpec.php' => 'tests/Contract/Runner/streamed/tests/WaitedSpec.php',
        'tests/WaitedSlowSpec.php' => 'tests/Contract/Runner/streamed/tests/WaitedSlowSpec.php',
    ];

    foreach ($copied as $into => $from) {
        copy(Tree::at($from), Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
    }

    Library::patched();
    $files = Paths::of(Path::of('src/Settled.php'), Path::of('src/Waited.php'));
    $run = static fn(MutationRequest $request): MutationResult|CannotJudge => Library::pest(Patching::on(Library::canary())->splitting(StreamSettings::of(
        enabled: true,
        children: 1,
        changeCheck: false,
        killersFirst: true,
        pace: new StreamPace(on: false, firstFork: FirstFork::Cheapest, earlyVerdicts: true),
    )))->runner()->mutate($request);
    $request = MutationRequest::of($files, WholeSuite::tests())
        ->narrowedTo($files, Narrowing::none()->toMutators(Mutators::named(PlusToMinus::class)));

    try {
        $whole = $run($request);
        putenv('LIBRARY_STALL=1');
        $stopped = $run($request->within(Seconds::of(30.0)));
    } finally {
        putenv('LIBRARY_STALL');

        foreach (array_keys($copied) as $into) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
        }
    }

    $judged = static fn(MutationResult|CannotJudge $result): array|string => $result instanceof MutationResult
        ? array_combine(
            array_map(static fn(Mutant $mutant): string => sprintf('%s:%d', $mutant->location()->file()->value(), $mutant->location()->start()->number()), [...$result->mutants()]),
            array_map(static fn(Mutant $mutant): array => [
                $mutant->status(),
                array_map(static fn(TestId $killer): string => $killer->value(), [...$mutant->killers()]),
                $result->evidence()->of($mutant->id())->isStreamed(),
            ], [...$result->mutants()]),
        )
        : $result->why();
    $settled = [MutantStatus::Killed, ['P\\Tests\\SettledSpec::__pest_evaluable_it_adds_one'], true];

    $named = Tree::at(sprintf('%s/.mutation-gate/pest/results.jsonl.streams', Library::DIRECTORY));

    expect(streamedPasses($stopped))->toBe([[Step::FirstPass, 2], [Step::SecondPass, 1]])
        ->and(is_file($named) ? file_get_contents($named) : '')->toMatch('~/mutation-gate-split-[0-9a-f]{32}$~')
        ->and($judged($whole))->toBe([
            'src/Settled.php:15' => $settled,
            'src/Waited.php:16' => [MutantStatus::Killed, ['P\\Tests\\WaitedSlowSpec::__pest_evaluable_it_doubles_two_into_four__slowly'], true],
        ])->and($judged($stopped))->toBe([
            'src/Settled.php:15' => $settled,
            'src/Waited.php:16' => [MutantStatus::Unjudged, [], false],
        ]);
})->skip(
    fn(): bool => ! Library::isInstalled() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill'),
    'the runner contracts job installs the fixture library, and split streams need a PHP that forks',
)->group(...$holds);
