<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_fill;
use function count;
use function max;
use function min;
use function uksort;

/**
 * Test files cut into the parts a run's shards each stream one of
 * (ADR-0117): each file, the longest first and files of equal time by name,
 * to the part with the least time so far, of those the fewest files, of
 * those the lowest, so every shard, and the plan that prices the parts,
 * cuts the same files alike.
 *
 * @internal
 */
final readonly class TestFileParts
{
    /**
     * Each file's part among at most this many, counted from 0, by its name.
     *
     * @param  array<string, float|int> $times each file's time, by its name
     * @return array<string, int>
     */
    public static function placed(array $times, int $parts): array
    {
        uksort(
            $times,
            static fn(string $one, string $two): int => [$times[$two], $one] <=> [$times[$one], $two],
        );
        $loads = array_fill(0, min(max(1, $parts), max(1, count($times))), [0.0, 0]);
        $placed = [];

        foreach ($times as $file => $time) {
            $least = self::leastOf($loads);
            $loads[$least] = [$loads[$least][0] + $time, $loads[$least][1] + 1];
            $placed[$file] = $least;
        }

        return $placed;
    }

    /**
     * The part with the least time so far, of those the fewest files, of
     * those the lowest.
     *
     * @param list<array{float, int}> $loads each part's time and files so far
     */
    private static function leastOf(array $loads): int
    {
        $least = 0;

        foreach ($loads as $at => $load) {
            $least = $load < $loads[$least] ? $at : $least;
        }

        return $least;
    }
}
