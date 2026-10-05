<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_filter;
use function array_values;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * A kept coverage map with some of its tests measured again (ADR-0023,
 * decision 3): every entry of those tests is replaced by what the new
 * measure holds, and every other test keeps its lines and its recorded
 * duration. A test the new measure holds and the kept map did not is added,
 * one of those tests the new measure no longer holds is gone, a line no test
 * runs any longer is one the run missed, and each file keeps every method
 * either map says some test executed. The lines the new measure itself
 * missed count for nothing: it ran some tests alone, and on a file none of
 * them loaded, php-code-coverage takes the executable lines from its own
 * reading of the source, not from what the run executed.
 */
final readonly class Remeasured
{
    /** The kept map, its entries of these tests replaced by those of a map that measured them again. */
    public static function over(CoverageMap $kept, TestIds $tests, CoverageMap $measured): CoverageMap
    {
        $lines = [];

        foreach ($kept->lines() as $line) {
            $staying = array_values(array_filter(
                [...$line],
                static fn(string $test): bool => ! $tests->has(TestId::of($test)),
            ));
            $lines[] = CoveredLine::of($line->file(), $line->line(), ...$staying);
        }

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

    /** @return list<TimedTest> how long each test of a map took, but for these, where the map timed it */
    private static function timed(CoverageMap $map, TestIds $leaving): array
    {
        $timed = [];

        foreach ($map->tests() as $test) {
            $duration = $map->durationOf($test);
            $timed = $duration instanceof Seconds && ! $leaving->has($test)
                ? [...$timed, TimedTest::of($test->value(), $duration->seconds())]
                : $timed;
        }

        return $timed;
    }
}
