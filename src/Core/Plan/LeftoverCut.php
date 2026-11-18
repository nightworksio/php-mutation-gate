<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_fill;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_search;
use function array_sum;
use function ceil;
use function count;
use function intval;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function usort;

/**
 * The shares the mutants each shard left at its deadline are cut into for
 * the second wave (ADR-0190): as many for a shard as its mutants' predicted
 * seconds fill jobs of the capacity it is given, at most one for each mutant, each
 * mutant, costliest first, given to the share predicted to end first.
 *
 * @internal
 */
final readonly class LeftoverCut
{
    /**
     * @param array<int, list<array{LeftMutant, float}>> $left   each shard's left mutants with their predicted
     *                                                           seconds, by the shard's number
     * @param array<int, Seconds>                      $capacity the seconds a job of the second wave has to judge
     *                                                           each shard's mutants in, by the shard's number
     */
    public static function of(Digest $plan, array $left, array $capacity): LeftoverShares
    {
        $shares = [];

        foreach ($left as $shard => $mutants) {
            $within = array_key_exists($shard, $capacity) ? $capacity[$shard] : Seconds::of(0.0);
            $shares = [...$shares, ...self::sharesOf(ShardId::of($shard), $mutants, $within)];
        }

        return LeftoverShares::cut($plan, ...$shares);
    }

    /**
     * @param  list<array{LeftMutant, float}> $mutants
     * @return list<LeftoverShare>
     */
    private static function sharesOf(ShardId $shard, array $mutants, Seconds $capacity): array
    {
        if ($mutants === []) {
            return [];
        }

        $total = array_sum(array_map(static fn(array $mutant): float => $mutant[1], $mutants));
        $count = max(1, min(count($mutants), intval(ceil($total / max($capacity->seconds(), 1.0)))));
        $loads = array_fill(0, $count, 0.0);
        $held = array_fill(0, $count, []);
        usort($mutants, static fn(array $one, array $other): int => $other[1] <=> $one[1]);

        foreach ($mutants as [$mutant, $seconds]) {
            $least = intval(array_search(min($loads), $loads, strict: true));
            $loads[$least] += $seconds;
            $held[$least][] = $mutant;
        }

        $shares = [];

        foreach (array_keys($held) as $index) {
            $shares[] = LeftoverShare::of($shard, $index + 1, ...$held[$index]);
        }

        return $shares;
    }
}
