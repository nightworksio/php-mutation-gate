<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_diff;
use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_values;
use function getcwd;
use function getenv;

use NightWorksIO\MutationGate\Adapter\Pest\CoveringFiles;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\LoadedMap;
use NightWorksIO\MutationGate\Adapter\Pest\Schemata;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Forking;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;

use function sprintf;
use function strval;

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
        $parts = self::partsOf($settings->part(), $settings);
        [$sites, $paths, $covering, $byId, $held] = PlannedMutants::of($suite, $coveredLines, $hints, $parts, $forks);

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
        );
        [$verdicts, $unrun] = self::firstPass($streaming, $sites, $held, $settings);
        $undecided = self::undecided($byId, $sites, $covering, $held, $verdicts);
        $booted = self::booted($sites, $verdicts);
        $again = self::streamedAgain($undecided, $sites, $verdicts, $settings) + $unrun + $booted;
        $left = self::left($again, $sites, $covering, $held, $unrun, $verdicts);
        $verdicts = $again === [] ? $verdicts : $streaming->pass(2, $again, $left, $verdicts);

        foreach ($byId as $test) {
            $verdicts->give($test->mutation->id, $test, $covering[$test->mutation->id]);
        }

        ForkStatesLine::writtenFor($verdicts, $results);
        StreamProgress::unnamed($results);

        StreamDirectory::removed($directory);
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
     * @param  array<string, list<string>>                                       $held  each mutant's covering tests the
     *                                                                                  shard's part holds
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

    /** The coverage map's test files cut into the run's parts, this shard's part among them; one part of every one. */
    private static function partsOf(TestPart $part, StreamSettings $settings): TestParts
    {
        return $part->isWhole()
            ? TestParts::whole()
            : TestParts::cut(
                $part,
                CoveringFiles::streamed(LoadedMap::tests()),
                LoadedMap::timed(),
                $settings->judging(),
            );
    }

    /**
     * The sites of the mutants whose site ran while a first-pass stream booted
     * its application once, which no test forked from it could reach: a
     * second pass boots the application in every test, as a run without
     * `splitStream.bootOnce` does.
     *
     * @param  array<string, array{string, int, string, array<string, string>}> $sites
     * @return array<string, array{string, int, string, array<string, string>}>
     */
    private static function booted(array $sites, StreamVerdicts $verdicts): array
    {
        $again = [];

        foreach ($verdicts->booted() as $mutant) {
            if (array_key_exists($mutant, $sites)) {
                [$key, $id, , $variables] = $sites[$mutant];
                $again[$mutant] = [$key, $id, '', $variables];
            }
        }

        return $again;
    }

    /**
     * The sites of the mutants the first pass left undecided that a second
     * forks at every covering test it has not yet: one forked first at its
     * likely killer alone, and one whose covering tests the shard's part
     * does not hold every one of.
     *
     * @param  array<string, MutationTest>                                      $byId
     * @param  array<string, array{string, int, string, array<string, string>}> $sites
     * @param  array<string, list<string>>                                       $covering
     * @param  array<string, list<string>>                                       $held
     * @return array<string, array{string, int, string, array<string, string>}>
     */
    private static function undecided(
        array $byId,
        array $sites,
        array $covering,
        array $held,
        StreamVerdicts $verdicts,
    ): array {
        $again = [];

        foreach ($byId as $test) {
            $mutant = $test->mutation->id;
            [$key, $id, $hint, $variables] = $sites[$mutant];
            $unfinished = $hint !== '' || $held[$mutant] !== $covering[$mutant];

            if ($unfinished && ! $verdicts->decides($mutant, $covering[$mutant])) {
                $again[$mutant] = [$key, $id, '', $variables];
            }
        }

        return $again;
    }

    /**
     * The sites of these undecided mutants a second pass streams: under
     * `splitStream.fallbackOwnRun`, none whose likely killer the first pass
     * fell back on for it, which its own run judges, that killer first
     * (ADR-0179).
     *
     * @param  array<string, array{string, int, string, array<string, string>}> $undecided
     * @param  array<string, array{string, int, string, array<string, string>}> $sites
     * @return array<string, array{string, int, string, array<string, string>}>
     */
    private static function streamedAgain(
        array $undecided,
        array $sites,
        StreamVerdicts $verdicts,
        StreamSettings $settings,
    ): array {
        if (! $settings->fallsBackToOwnRuns()) {
            return $undecided;
        }

        $again = [];

        foreach ($undecided as $mutant => $site) {
            $killer = $sites[$mutant][2];

            if ($killer === '' || ! $verdicts->leaves(strval($mutant), $killer)) {
                $again[$mutant] = $site;
            }
        }

        return $again;
    }

    /**
     * The covering tests a second pass streams for each of these mutants:
     * those the first pass did not fork it at, or every one where it forked
     * it at its likely killer alone, at none, or under an application booted
     * once, which no forked test could reach.
     *
     * @param  array<string, array{string, int, string, array<string, string>}> $again
     * @param  array<string, array{string, int, string, array<string, string>}> $sites
     * @param  array<string, list<string>>                                       $covering
     * @param  array<string, list<string>>                                       $held
     * @param  array<string, array{string, int, string, array<string, string>}> $unrun
     * @return array<string, list<string>>
     */
    private static function left(
        array $again,
        array $sites,
        array $covering,
        array $held,
        array $unrun,
        StreamVerdicts $verdicts,
    ): array {
        $booted = array_flip($verdicts->booted());
        $left = [];

        foreach (array_keys($again) as $mutant) {
            $whole = $sites[$mutant][2] !== ''
                || array_key_exists($mutant, $unrun)
                || array_key_exists($mutant, $booted);
            $rest = $whole ? [] : array_values(array_diff($covering[$mutant], $held[$mutant]));
            $left[$mutant] = $rest === [] ? $covering[$mutant] : $rest;
        }

        return $left;
    }
}
