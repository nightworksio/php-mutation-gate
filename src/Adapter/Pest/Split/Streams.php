<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_diff_key;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function count;
use function getcwd;
use function getenv;
use function max;
use function microtime;

use NightWorksIO\MutationGate\Adapter\Pest\CoveringFiles;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\LoadedMap;
use NightWorksIO\MutationGate\Adapter\Pest\OnlyList;
use NightWorksIO\MutationGate\Adapter\Pest\PcovTests;
use NightWorksIO\MutationGate\Adapter\Pest\Schemata;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Forking;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;

use function sprintf;

/**
 * The split streams of a patched Pest mutation run (ADR-0117): in Pest's
 * own process, once the mutants are made, every mutant its file's schema
 * switches, that tests of the shard's part of the test files cover, is
 * judged in streams of the tests that cover them, each its own Pest run of
 * their files, those tests alone where the run selects them (ADR-0181), and
 * is given its result as its own run would give it. A
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
     * test alone, and a second, of the tests that cover what the first left
     * undecided, at every covering test (ADR-0117). A mutant the first pass
     * settled is given its result before the second starts, under
     * `splitStream.earlyVerdicts` (ADR-0194). The run's parts are those its
     * making cut, where it made the lines its part's tests reach (ADR-0193).
     *
     * @param array<string, array<int, array<int, string>>> $coveredLines
     * @param list<string>                                    $arguments a mutant's own run's arguments
     *
     * @throws UnmadeDirectory
     */
    public static function judge(
        MutationSuite $suite,
        array $coveredLines,
        array $arguments,
        int $processes,
        PartLines|NotGiven $made,
    ): void {
        $settings = StreamSettings::fromEnvironment();

        if ($settings->recording() !== '') {
            EarlyStreams::judge($suite, $arguments, $processes, $settings);

            return;
        }

        $forks = Forking::isAvailable();
        $ordered = $forks && $settings->killersFirst();
        $hints = $ordered
            ? KillerHints::of($suite, getenv(GateVariable::Order->value), Root::of(sprintf('%s', getcwd())))
            : KillerHints::none();
        $first = $ordered ? FirstForks::of($settings->pace()->firstFork, LoadedMap::timed()) : FirstForks::atKillers();
        $parts = $made instanceof PartLines ? $made->parts() : self::partsOf($settings->part(), $settings);
        [$sites, $paths, $covering, $byId, $held, $cheapest] = PlannedMutants::of(
            $suite,
            $coveredLines,
            $hints,
            $first,
            $parts,
            $forks,
        );

        if ($byId === []) {
            return;
        }

        $directory = StreamDirectory::made();
        $results = getenv(GateVariable::Results->value);
        Schemata::leftIn($directory);
        StreamProgress::named($results, $directory);
        $streaming = new Streaming(
            $directory,
            $paths,
            $arguments,
            $processes,
            $settings->children(),
            $settings->lines(),
            $settings->bootsOnce(),
            $settings->reconnects(),
            $settings->selectsCoveringTests(),
            $settings->pace(),
            PcovTests::of($coveredLines),
            StreamSlowdown::carried($settings->pace()->carry, $results),
        );
        $bound = self::boundOf($covering, $processes);
        $from = microtime(as_float: true);
        StreamPasses::started($results, Step::FirstPass, count($sites), $bound);
        [$verdicts, $unrun] = self::firstPass($streaming, $sites, self::firstHeld($held, $sites, $cheapest), $settings);
        StreamPasses::ended($results, Step::FirstPass, microtime(as_float: true) - $from, count($sites), $bound);
        [$again, $left] = new SecondPass($sites, $covering, $held, $cheapest)->of($byId, $unrun, $verdicts, $settings);
        $settled = $settings->pace()->earlyVerdicts ? SettledFirst::of($byId, $covering, $again, $left) : [];
        self::given($verdicts, $settled, $covering);
        $from = microtime(as_float: true);
        StreamPasses::started($results, Step::SecondPass, count($again), $bound);
        $verdicts = $again === [] ? $verdicts : $streaming->pass(2, $again, $left, $verdicts);
        StreamPasses::ended($results, Step::SecondPass, microtime(as_float: true) - $from, count($again), $bound);
        self::given($verdicts, array_diff_key($byId, $settled), $covering);

        ForkStatesLine::writtenFor($verdicts, $results);
        StreamProgress::unnamed($results);

        StreamDirectory::removed($directory);
    }

    /** The map's test files cut into the run's parts as these settings cut them, this shard's part among them. */
    public static function parts(StreamSettings $settings): TestParts
    {
        return self::partsOf($settings->part(), $settings);
    }

    /**
     * The wall seconds a pass would take were each mutant forked at every
     * covering test the shard judges it by, those tests' seconds by the map
     * shared by the run's processes (ADR-0196).
     *
     * @param array<string, list<string>> $covering
     */
    private static function boundOf(array $covering, int $processes): float
    {
        $times = LoadedMap::timed();
        $seconds = 0.0;

        foreach ($covering as $tests) {
            foreach ($tests as $test) {
                $seconds += array_key_exists($test, $times) ? $times[$test] : 0.0;
            }
        }

        return $seconds / max(1, $processes);
    }

    /**
     * Gives each of these mutants its result, as the streams' verdicts so
     * far judged it, each written as it is given.
     *
     * @param array<string, MutationTest> $mutants
     * @param array<string, list<string>> $covering
     */
    private static function given(StreamVerdicts $verdicts, array $mutants, array $covering): void
    {
        foreach ($mutants as $test) {
            $verdicts->give($test->mutation->id, $test, $covering[$test->mutation->id]);
        }
    }

    /**
     * The first pass's verdicts, and the sites it left for the second: under
     * `splitStream.killerStreams`, where any site has a likely killer and
     * PHPUnit selects tests by their ids, a pass that runs only those
     * killers, leaving every site without one to the second (ADR-0136);
     * otherwise a pass of the files of the shard's part that cover them,
     * leaving none.
     *
     * @param  array<string, array{string, int, string, array<string, string>}> $sites
     * @param  array<string, list<string>>                                       $held  the covering tests a first pass
     *                                                                                  streams for each mutant
     * @return array{StreamVerdicts, array<string, array{string, int, string, array<string, string>}>}
     */
    private static function firstPass(Streaming $streaming, array $sites, array $held, StreamSettings $settings): array
    {
        $hinted = array_filter($sites, static fn(array $site): bool => $site[2] !== '');
        $killed = $settings->streamsKillers() && $hinted !== []
            ? $streaming->killersPass(1, $hinted, StreamVerdicts::none())
            : NotGiven::value();

        return $killed instanceof StreamVerdicts
            ? [$killed, array_diff_key($sites, $hinted)]
            : [$streaming->pass(1, $sites, $held, StreamVerdicts::none()), []];
    }

    /**
     * The covering tests a first pass streams for each mutant: those the
     * shard's part holds, or of one it forks at its cheapest, that test
     * alone (ADR-0187).
     *
     * @param  array<string, list<string>>                                       $held
     * @param  array<string, array{string, int, string, array<string, string>}> $sites
     * @param  array<string, true>                                               $cheapest
     * @return array<string, list<string>>
     */
    private static function firstHeld(array $held, array $sites, array $cheapest): array
    {
        foreach (array_keys($cheapest) as $mutant) {
            $held[$mutant] = [$sites[$mutant][2]];
        }

        return $held;
    }

    /**
     * The coverage map's test files cut into the run's parts, this shard's
     * part among them, making what the run is told to where it is told; one
     * part of every one.
     */
    private static function partsOf(TestPart $part, StreamSettings $settings): TestParts
    {
        if ($part->isWhole()) {
            return TestParts::whole();
        }

        $parts = TestParts::cut(
            $part,
            CoveringFiles::streamed(LoadedMap::tests()),
            LoadedMap::timed(),
            $settings->judging(),
        );

        return OnlyList::in((string) getenv(GateVariable::Only->value)) === [] ? $parts : $parts->makingWhatItIsTold();
    }
}
