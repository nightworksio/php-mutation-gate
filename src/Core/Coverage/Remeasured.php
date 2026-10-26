<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_filter;

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * A kept coverage map with some of its tests measured again (ADR-0023):
 * every entry of those tests is replaced by what the new measure holds,
 * and every other test keeps its lines and its recorded duration. A
 * test the new measure holds and the kept map did not is added, one of
 * those tests the new measure no longer holds is gone, a line no test
 * runs any longer is one the run missed, and each file keeps every
 * method either map says some test executed. The lines the new measure
 * itself missed count for nothing: it ran some tests alone, and on a
 * file none of them loaded, php-code-coverage takes the executable
 * lines from its own reading of the source, not from what the run
 * executed. Where the files changed since the kept map's commit are
 * known, a test measured again that the new measure still holds keeps
 * the lines it ran in every other file too: a framework runs its boot
 * code only while its cache is cold, so a measure on a warm cache misses
 * what an earlier measure of the same, unchanged file saw it run.
 */
final readonly class Remeasured
{
    /**
     * The kept map, its entries of these tests replaced by those of a map
     * that measured them again, each of those the new map holds keeping its
     * lines in every file these do not name as changed since the kept map's
     * commit.
     */
    public static function over(
        CoverageMap $kept,
        TestIds $tests,
        CoverageMap $measured,
        Paths|NotGiven $changed = new NotGiven(),
    ): CoverageMap {
        $lines = self::staying($kept, $tests, $measured->tests(), $changed);

        foreach ($measured->lines() as $line) {
            if ([...$line] !== []) {
                $lines[] = $line;
            }
        }

        $merged = CoverageMap::of(...$lines)
            ->timedEach(...self::timed($kept, $tests), ...self::timed($measured, TestIds::none()));

        foreach ([$kept, $measured] as $map) {
            foreach ($map->methods() as $file => $methods) {
                $merged = $merged->executing($file, ...$methods);
            }
        }

        return $merged;
    }

    /**
     * Each line of the kept map, with the tests that stay on it: every one
     * not measured again, and, on a file not named as changed, every one
     * measured again that the new measure holds.
     *
     * @return list<CoveredLine>
     */
    private static function staying(CoverageMap $kept, TestIds $again, TestIds $held, Paths|NotGiven $changed): array
    {
        $lines = [];

        foreach ($kept->lines() as $line) {
            $unchanged = $changed instanceof Paths && ! $changed->has($line->file());
            $staying = array_filter(
                [...$line],
                static fn(string $test): bool => ! $again->has(TestId::of($test))
                    || ($unchanged && $held->has(TestId::of($test))),
            );
            $lines[] = CoveredLine::of($line->file(), $line->line(), ...$staying);
        }

        return $lines;
    }

    /** @return list<TimedTest> how long each test of a map took, but for these, where the map timed it */
    private static function timed(CoverageMap $map, TestIds $leaving): array
    {
        $timed = [];

        foreach ($map->tests() as $test) {
            $duration = $map->durationOf($test);

            if (! $duration instanceof Seconds || $leaving->has($test)) {
                continue;
            }

            $timed[] = TimedTest::of($test->value(), $duration->seconds());
        }

        return $timed;
    }
}
