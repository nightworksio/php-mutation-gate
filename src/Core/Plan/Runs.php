<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_key_last;
use function array_last;
use function array_map;
use function array_slice;
use function array_sum;
use function count;

/**
 * Units cut, in the order given, into consecutive runs of about equal cost.
 * Each cut falls on the first unit that takes its run past an equal share of
 * the total, so no run is empty, and there are at most as many runs as asked.
 */
final readonly class Runs
{
    /**
     * @param  list<Weighed>       $units
     * @return list<list<Weighed>>
     */
    public static function into(array $units, int $count): array
    {
        $share = self::costOf($units) / $count;
        $runs = [[]];
        $weighed = 0.0;

        foreach ($units as $unit) {
            $runs[array_key_last($runs)][] = $unit;
            $weighed += $unit->cost()->seconds();

            if (count($runs) < $count && $weighed >= $share * count($runs)) {
                $runs[] = [];
            }
        }

        return array_last($runs) === [] ? array_slice($runs, 0, -1) : $runs;
    }

    /** @param list<Weighed> $units */
    public static function costOf(array $units): float
    {
        return array_sum(array_map(static fn(Weighed $unit): float => $unit->cost()->seconds(), $units));
    }
}
