<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_diff_key;
use function array_filter;
use function array_key_exists;
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
        [$sites, $paths, $covering, $byId] = PlannedMutants::of($suite, $coveredLines, $hints, $parts, $forks);

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
            $settings->lines(),
            $settings->bootsOnce(),
            $settings->reconnects(),
        );
        [$verdicts, $unrun] = self::firstPass($streaming, $sites, $settings);
        $again = [...self::undecided($sites, $covering, $verdicts), ...$unrun, ...self::booted($sites, $verdicts)];
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

}
