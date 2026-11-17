<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSettings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
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
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;
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

it('kills in its stream a mutant a test tells apart, or whose test ends its own process, stops at its limit one that never ends, and runs on its own one only a test holding a socket, a writable file or a file-backed database reaches', function (): void {
    $source = Tree::at(sprintf('%s/src/Streamed.php', Library::DIRECTORY));
    $test = Tree::at(sprintf('%s/tests/StreamedSpec.php', Library::DIRECTORY));
    copy(Tree::at('tests/Contract/Runner/streamed/src/Streamed.php'), $source);
    copy(Tree::at('tests/Contract/Runner/streamed/tests/StreamedSpec.php'), $test);
    Library::patched();

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

    expect($judged)->toBe([
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
        StreamSettings::of(enabled: true, children: 1, changeCheck: true, killersFirst: true)->streamingKillers(killerStreams: true),
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
    ]);
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

it('leaves a mutant whose site the application\'s boot runs to a second pass that boots in every test, under splitStream.bootOnce', function (): void {
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

    try {
        $result = Library::pest(Patching::on(Library::canary())->splitting(
            StreamSettings::of(enabled: true, children: 1, changeCheck: false, killersFirst: false)->bootingOnce(bootOnce: true),
        ))->runner()->mutate(
            MutationRequest::of(Paths::of(Path::of('src/Booted.php')), WholeSuite::tests())->narrowedTo(
                Paths::of(Path::of('src/Booted.php')),
                Narrowing::none()->toMutators(Mutators::named(PlusToMinus::class)),
            ),
        );
    } finally {
        foreach (array_keys($copied) as $into) {
            unlink(Tree::at(sprintf('%s/%s', Library::DIRECTORY, $into)));
        }

        rmdir($bootstrap);
    }

    expect(streamedJudged($result))->toBe([12 => [MutantStatus::Killed, ['P\\Tests\\BootedSpec::__pest_evaluable_it_readies_the_services'], true]]);
})->skip(
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
