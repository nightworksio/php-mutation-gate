<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_key_exists;
use function array_merge;
use function count;
use function max;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function usort;

/**
 * The shares the mutants each shard left at its deadline are cut into for
 * the second wave (ADR-0190), priced as a stream runs them: a share costs
 * the seconds the map timed the test files covering any of its mutants at,
 * each file once. A shard's mutants go in groups of a source file each,
 * costliest first, each to the share it adds the fewest seconds to that it
 * still fits the shard's capacity in, else to a share of its own. Where the
 * shares of every shard come to more than one matrix of jobs holds, every
 * capacity grows and the mutants are cut again, until they fit one or each
 * shard has a single share.
 *
 * @internal
 */
final readonly class LeftoverCut
{
    /** The most jobs GitHub makes of one matrix. */
    public const int MATRIX_MOST = 256;

    /** How much each capacity grows by where a cut makes more shares than one matrix holds. */
    private const float GROWTH = 1.25;

    /** The least seconds a share is given, where a shard has none left to judge in. */
    private const float LEAST = 1.0;

    /**
     * @param array<int, list<array{LeftMutant, list<string>}>> $left     each shard's left mutants with the test
     *                                                                    files covering each, by the shard's number
     * @param array<string, float>                              $timed    the seconds the map timed each test file's
     *                                                                    tests at together, by the file
     * @param array<int, Seconds>                               $capacity the seconds a job of the second wave has
     *                                                                    to judge each shard's mutants in, by its
     *                                                                    number
     */
    public static function of(Digest $plan, array $left, array $timed, array $capacity): LeftoverShares
    {
        $groups = [];

        foreach ($left as $shard => $mutants) {
            $groups[$shard] = self::groupsOf($mutants, $timed);
        }

        $grown = 1.0;

        do {
            [$shares, $split] = self::cutAt($groups, $timed, $capacity, $grown);
            $grown *= self::GROWTH;
        } while ($split && count($shares) > self::MATRIX_MOST);

        return LeftoverShares::cut($plan, ...$shares);
    }

    /**
     * The shares of every shard with each capacity grown this many times, and whether any shard has more than one.
     *
     * @param  array<int, list<LeftGroup>>          $groups
     * @param  array<string, float>                 $timed
     * @param  array<int, Seconds>                  $capacity
     * @return array{list<LeftoverShare>, bool}
     */
    private static function cutAt(array $groups, array $timed, array $capacity, float $grown): array
    {
        $shares = [];
        $split = false;

        foreach ($groups as $shard => $each) {
            $within = array_key_exists($shard, $capacity) ? $capacity[$shard]->seconds() : 0.0;
            $cut = self::sharesOf(ShardId::of($shard), $each, $timed, max($within, self::LEAST) * $grown);
            $split = $split || count($cut) > 1;
            $shares = [...$shares, ...$cut];
        }

        return [$shares, $split];
    }

    /**
     * @param  list<LeftGroup>      $groups
     * @param  array<string, float> $timed
     * @return list<LeftoverShare>
     */
    private static function sharesOf(ShardId $shard, array $groups, array $timed, float $capacity): array
    {
        $shares = [];

        foreach ($groups as $group) {
            $into = self::fitting($shares, $group, $timed, $capacity);
            $shares = $into instanceof NotGiven ? [...$shares, $group] : self::joinedAt($shares, $into, $group, $timed);
        }

        $cut = [];

        foreach ($shares as $index => $share) {
            $cut[] = LeftoverShare::of($shard, $index + 1, ...$share->mutants());
        }

        return $cut;
    }

    /**
     * The shares with a group joined to the one at this place.
     *
     * @param  list<LeftGroup>      $shares
     * @param  array<string, float> $timed
     * @return list<LeftGroup>
     */
    private static function joinedAt(array $shares, int $into, LeftGroup $group, array $timed): array
    {
        $joined = [];

        foreach ($shares as $index => $share) {
            $joined[] = $index === $into ? $share->joined($group, $share->added($group, $timed)) : $share;
        }

        return $joined;
    }

    /**
     * The mutants in groups of a source file each, costliest first.
     *
     * @param  list<array{LeftMutant, list<string>}> $mutants
     * @param  array<string, float>                  $timed
     * @return list<LeftGroup>
     */
    private static function groupsOf(array $mutants, array $timed): array
    {
        $held = [];
        $covering = [];

        foreach ($mutants as [$mutant, $files]) {
            $held[$mutant->file()->value()][] = $mutant;
            $covering[$mutant->file()->value()][] = $files;
        }

        $groups = [];

        foreach ($held as $file => $each) {
            $groups[] = LeftGroup::of($each, array_merge(...$covering[$file]), $timed);
        }

        usort($groups, static fn(LeftGroup $one, LeftGroup $other): int => $other->seconds() <=> $one->seconds());

        return $groups;
    }

    /**
     * The share a group adds the fewest seconds to that it still fits, the first of those; none where it fits none.
     *
     * @param list<LeftGroup>      $shares
     * @param array<string, float> $timed
     */
    private static function fitting(array $shares, LeftGroup $group, array $timed, float $capacity): int|NotGiven
    {
        $into = NotGiven::value();
        $least = 0.0;

        foreach ($shares as $index => $share) {
            $added = $share->added($group, $timed);

            if ($share->seconds() + $added <= $capacity && ($into instanceof NotGiven || $added < $least)) {
                $into = $index;
                $least = $added;
            }
        }

        return $into;
    }
}
