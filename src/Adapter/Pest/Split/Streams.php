<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_diff_key;
use function array_filter;
use function array_key_exists;
use function array_unique;
use function array_values;
use function file_put_contents;
use function getcwd;
use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\CoveringFiles;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\LoadedMap;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\Schemata;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Forking;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Plugins\Mutate;

use function sprintf;

/**
 * The split streams of a patched Pest mutation run (ADR-0117): in Pest's
 * own process, once the mutants are made, every mutant its file's schema
 * switches, that tests of the shard's part of the test files cover, is
 * judged in streams of the test files that cover them, each its own Pest run
 * of those files, and is given its result as its own run would give it. A
 * mutant a stream cannot judge is left for its own run. Where a run cuts its
 * test files across its shards, a mutant no stream can judge runs on its own
 * in the shard that owns it alone, and one no test of the part covers is left
 * to the parts that hold its tests (see Parted); where PHP cannot fork, no
 * mutant is streamed.
 *
 * @internal
 */
final readonly class Streams
{
    /** Whether the gate asks this run to split its streams. */
    public static function areOn(): bool
    {
        return StreamSettings::fromEnvironment()->isOn() && Schemata::areOn();
    }

    /**
     * Judges every mutant of the suite a stream can, giving each its result:
     * where the ledger names a likely killer, a first stream forks it at that
     * test alone, and a second, of the files that cover what the first left
     * undecided, at every covering test (ADR-0117).
     *
     * @param array<string, array<int, array<int, string>>> $coveredLines
     * @param list<string>                                    $arguments a mutant's own run's arguments
     *
     * @throws UnmadeDirectory
     */
    public static function judge(MutationSuite $suite, array $coveredLines, array $arguments, int $processes): void
    {
        $settings = StreamSettings::fromEnvironment();

        if ($settings->recording() !== '') {
            EarlyStreams::judge($suite, $arguments, $processes, $settings);

            return;
        }

        $forks = Forking::isAvailable();
        $hints = $forks && $settings->killersFirst()
            ? KillerHints::of($suite, getenv(GateVariable::Order->value), Root::of(sprintf('%s', getcwd())))
            : KillerHints::none();
        $parts = self::partsOf($settings->part());
        [$sites, $paths, $covering, $byId] = self::planned($suite, $coveredLines, $hints, $parts, $forks);

        if ($byId === []) {
            return;
        }

        $directory = StreamDirectory::made();
        Schemata::leftIn($directory);
        $streaming = new Streaming(
            $directory,
            $paths,
            $covering,
            $arguments,
            $processes,
            $settings->children(),
            $settings->recording(),
        );
        [$verdicts, $unrun] = self::firstPass($streaming, $sites, $settings);
        $again = [...self::undecided($sites, $covering, $verdicts), ...$unrun];
        $verdicts = $again === [] ? $verdicts : $streaming->pass(2, $again, $verdicts);

        foreach ($byId as $mutant => $test) {
            $verdicts->give($mutant, $test, $covering[$mutant]);
        }

        self::counted($verdicts);

        StreamDirectory::removed($directory);
    }

    /**
     * Writes how many forks read their state, and how many found it alike,
     * to the run's results, where the streams counted any (H5).
     */
    private static function counted(StreamVerdicts $verdicts): void
    {
        [$forks, $alike] = $verdicts->forkStates();
        $results = getenv(GateVariable::Results->value);

        if ($forks > 0 && is_string($results) && $results !== '') {
            file_put_contents($results, RecordLine::forkStates($forks, $alike), FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * The first pass's verdicts, and the sites it left for the second: under
     * `splitStream.killerStreams`, where any site has a likely killer and
     * PHPUnit selects tests by their ids, a pass that runs only those
     * killers, leaving every site without one to the second (ADR-0136);
     * otherwise a pass of every covering test file, leaving none.
     *
     * @param  array<string, array{string, int, string, array<string, string>}> $sites
     * @return array{StreamVerdicts, array<string, array{string, int, string, array<string, string>}>}
     */
    private static function firstPass(Streaming $streaming, array $sites, StreamSettings $settings): array
    {
        $hinted = array_filter($sites, static fn(array $site): bool => $site[2] !== '');
        $killed = $settings->streamsKillers() && $hinted !== []
            ? $streaming->killersPass(1, $hinted, StreamVerdicts::none())
            : NotGiven::value();

        return $killed instanceof StreamVerdicts
            ? [$killed, array_diff_key($sites, $hinted)]
            : [$streaming->pass(1, $sites, StreamVerdicts::none()), []];
    }

    /** The coverage map's test files cut into the run's parts, this shard's part among them; one part of every one. */
    private static function partsOf(TestPart $part): TestParts
    {
        return $part->isWhole()
            ? TestParts::whole()
            : TestParts::cut($part, CoveringFiles::streamed(LoadedMap::tests()), LoadedMap::timed());
    }

    /**
     * Each mutant a stream of the shard's part can judge: its site, by Pest's
     * id, the source file of each schema, by its key, the tests of the part
     * that cover each, and each mutant.
     *
     * @param  array<string, array<int, array<int, string>>> $coveredLines
     * @return array{
     *     array<string, array{string, int, string, array<string, string>}>,
     *     array<string, string>,
     *     array<string, list<string>>,
     *     array<string, MutationTest>,
     * }
     */
    private static function planned(
        MutationSuite $suite,
        array $coveredLines,
        KillerHints $hints,
        TestParts $parts,
        bool $forks,
    ): array {
        $sites = [];
        $paths = [];
        $covering = [];
        $byId = [];

        foreach (self::testsOf($suite) as $test) {
            $switch = $forks ? Schemata::switchOf($test->mutation) : NotGiven::value();
            $covered = self::coveringOf($test, $coveredLines);
            $held = self::heldOf($test, $covered, $parts, $switch instanceof NotGiven);

            if ($switch instanceof NotGiven || $held === []) {
                continue;
            }

            $mutant = $test->mutation->id;
            $paths[$switch[0]] = (string) $test->mutation->file->getRealPath();
            $sites[$mutant] = [...$switch, $hints->killerOf($mutant, $held), self::variablesOf($test)];
            $covering[$mutant] = $held;
            $byId[$mutant] = $test;
        }

        return [$sites, $paths, $covering, $byId];
    }

    /** @return list<MutationTest> every mutant of the suite, in the order Pest holds them */
    private static function testsOf(MutationSuite $suite): array
    {
        $tests = [];

        foreach ($suite->repository->all() as $collection) {
            $tests = [...$tests, ...$collection->tests()];
        }

        return $tests;
    }

    /**
     * The covering tests of the shard's part a stream judges a mutant by;
     * none where no stream does: one no test covers, which Pest leaves
     * uncovered; one no stream can judge, which runs on its own in the shard
     * that owns it and is left by every other; and one no test of the part
     * covers, which is left to the parts that hold its tests.
     *
     * @param  list<string> $covered every test that covers it
     * @return list<string>
     */
    private static function heldOf(MutationTest $test, array $covered, TestParts $parts, bool $unstreamed): array
    {
        $held = $unstreamed ? [] : $parts->within($covered);
        $leaves = $unstreamed ? ! $parts->owns($covered) : $held === [];

        if ($covered !== [] && $leaves) {
            Parted::unreached($test);
        }

        if ($held !== []) {
            Parted::judgedBy($test, $parts->shareOf($covered), $held);
        }

        return $held;
    }

    /**
     * The sites of the mutants forked first at a likely killer that the first
     * pass left undecided, to fork at every covering test.
     *
     * @param  array<string, array{string, int, string, array<string, string>}> $sites
     * @param  array<string, list<string>>                                       $covering
     * @return array<string, array{string, int, string, array<string, string>}>
     */
    private static function undecided(array $sites, array $covering, StreamVerdicts $verdicts): array
    {
        $again = [];

        foreach ($sites as $mutant => [$key, $id, $hint, $variables]) {
            if ($hint !== '' && ! $verdicts->decides($mutant, $covering[$mutant])) {
                $again[$mutant] = [$key, $id, '', $variables];
            }
        }

        return $again;
    }

    /**
     * The tests that cover a mutant, as Pest gathers them for its filter.
     *
     * @param  array<string, array<int, array<int, string>>> $coveredLines
     * @return list<string>
     */
    private static function coveringOf(MutationTest $test, array $coveredLines): array
    {
        $covering = [];
        $file = (string) $test->mutation->file->getRealPath();

        for ($line = $test->mutation->startLine; $line <= $test->mutation->endLine; $line++) {
            $tests = array_key_exists($file, $coveredLines) && array_key_exists($line, $coveredLines[$file])
                ? $coveredLines[$file][$line]
                : [];
            $covering = [...$covering, ...$tests];
        }

        return array_values(array_unique($covering));
    }

    /** @return array<string, string> what a mutant's own run is told, which a child's subprocesses inherit */
    private static function variablesOf(MutationTest $test): array
    {
        return [
            Mutate::ENV_MUTATION_TESTING => (string) $test->mutation->file->getRealPath(),
            Mutate::ENV_MUTATION_FILE => $test->mutation->modifiedSourcePath,
            ...Schemata::variablesOf($test->mutation),
        ];
    }
}
