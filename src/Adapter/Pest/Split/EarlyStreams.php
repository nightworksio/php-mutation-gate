<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_filter;
use function array_key_exists;
use function array_keys;

use NightWorksIO\MutationGate\Adapter\Pest\CoveringFiles;
use NightWorksIO\MutationGate\Adapter\Pest\LoadedMap;
use NightWorksIO\MutationGate\Adapter\Pest\Schemata;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Forking;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;

/**
 * The streams of a run planned without a fresh map (ADR-0135): its map
 * cannot tell what covers a mutant, so each stream runs every test file of
 * its part, the kept map's and those it does not know, forking at every
 * site a test reaches, and its parent records the lines each test
 * executes. What a mutant's tests are is then read from those lines: a
 * mutant is judged by the tests that executed it alone, and one no test
 * executed is uncovered. Where PHP cannot fork, or pcov cannot collect the
 * lines, no stream runs, nothing is recorded, and the verdict keys nothing.
 *
 * @internal
 */
final readonly class EarlyStreams
{
    /**
     * Judges every mutant of the suite a stream can, as {@see Streams::judge()} does for a run with a fresh map.
     *
     * @param list<string> $arguments a mutant's own run's arguments
     *
     * @throws UnmadeDirectory
     */
    public static function judge(MutationSuite $suite, array $arguments, int $processes, StreamSettings $settings): void
    {
        if (! Forking::isAvailable() || ! Recorded::usable()) {
            return;
        }

        [$sites, $paths, $byId] = self::planned($suite);

        if ($byId === []) {
            return;
        }

        $directory = StreamDirectory::made();
        Schemata::leftIn($directory);
        $units = self::partOf($settings);
        $streaming = new Streaming(
            $directory,
            $paths,
            [],
            $arguments,
            $processes,
            $settings->children(),
            $settings->recording(),
        );
        $verdicts = $streaming->over(1, $sites, $units, StreamVerdicts::none());
        $covering = RecordedCovering::of($settings->recording(), $byId);

        foreach ($byId as $mutant => $test) {
            if ($covering[$mutant] === []) {
                $verdicts->uncovered($test);

                continue;
            }

            $verdicts->give($mutant, $test, $covering[$mutant]);
        }

        StreamDirectory::removed($directory);
    }

    /**
     * Every mutant of the suite a stream can judge: its site, the source
     * file of each schema, by its key, and each mutant, by Pest's id.
     *
     * @return array{
     *     array<string, array{string, int, string, array<string, string>}>,
     *     array<string, string>,
     *     array<string, MutationTest>,
     * }
     */
    private static function planned(MutationSuite $suite): array
    {
        $sites = [];
        $paths = [];
        $byId = [];

        foreach ($suite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                $switch = Schemata::switchOf($test->mutation);

                if ($switch instanceof NotGiven) {
                    continue;
                }

                $mutant = $test->mutation->id;
                $paths[$switch[0]] = (string) $test->mutation->file->getRealPath();
                $sites[$mutant] = [...$switch, '', PlannedMutants::variablesOf($test)];
                $byId[$mutant] = $test;
            }
        }

        return [$sites, $paths, $byId];
    }

    /**
     * Every test file of the shard's part: the kept map's, cut by its times,
     * and every other test file this process loaded, which the map does not
     * know.
     *
     * @return array<string, array{files: list<string>, tests: list<string>}>
     */
    private static function partOf(StreamSettings $settings): array
    {
        $units = CoveringFiles::everyCase(CoveringFiles::streamed(LoadedMap::tests()));
        $part = $settings->part();

        if ($part->isWhole()) {
            return $units;
        }

        $assigned = TestParts::assigned($units, LoadedMap::timed(), $part->parts());
        $mine = array_filter(
            array_keys($units),
            static fn(string $file): bool => array_key_exists($file, $assigned)
                ? $assigned[$file] === $part->number() - 1
                : $part->number() === 1,
        );
        $held = [];

        foreach ($mine as $file) {
            $held[$file] = $units[$file];
        }

        return $held;
    }
}
