<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_key_exists;
use function array_map;
use function array_merge;
use function array_slice;
use function array_values;
use function count;
use function max;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sqrt;
use function usort;

/**
 * The shares the mutants each shard left at its deadline are cut into for
 * the second wave (ADR-0190), priced at the wall seconds a job's split
 * streams take over them (ADR-0195). A shard's mutants go in groups of a
 * source file each, or, where a file's group does not fit a job alone, of a
 * mutant each, costliest first, each to the share, of the latest few it
 * still fits the shard's capacity in, it adds the fewest seconds to, else to
 * a share of its own; one that fits no job alone takes a share of its own.
 * Where the shares of every shard come to more than the most jobs given,
 * one matrix of jobs where none is given, every capacity grows, by a
 * quarter or by the root of how many times too many shares there are,
 * whichever is more, and the mutants are cut again, until they fit or each
 * shard has a single share; a share never holds two shards' mutants.
 * Shares cut for jobs that run their own tail say so (ADR-0303).
 *
 * @internal
 */
final readonly class LeftoverCut
{
    /** The most jobs GitHub makes of one matrix. */
    public const int MATRIX_MOST = 256;

    /**
     * How much each capacity grows by, at least, where a cut makes more
     * shares than one matrix holds; by the root of how many times more it made, where that is more.
     */
    private const float GROWTH = 1.25;

    /** How many of the latest shares a group is offered to, so a cut of many mutants stays quick. */
    private const int OFFERED = 64;

    /** The least seconds a share is given, where a shard has none left to judge in. */
    private const float LEAST = 1.0;

    /**
     * @param array<int, list<array{LeftMutant, list<string>, LeftForks}>> $left     each shard's left mutants with
     *                                                                               the test files covering each and
     *                                                                               its forks' seconds, by the
     *                                                                               shard's number
     * @param array<string, float>                                         $timed    the seconds the map timed each
     *                                                                               test file's tests at together,
     *                                                                               by the file
     * @param array<int, Seconds>                                          $capacity the seconds a job of the second
     *                                                                               wave has to judge each shard's
     *                                                                               mutants in, by its number
     * @param int                                                          $most     the most shares there may be
     * @param bool                                                         $tails    whether each share's job runs
     *                                                                               its own tail
     */
    public static function of(
        Digest $plan,
        array $left,
        array $timed,
        array $capacity,
        StreamWall $wall,
        int $most = self::MATRIX_MOST,
        bool $tails = false,
    ): LeftoverShares {
        $groups = [];

        foreach ($left as $shard => $mutants) {
            $groups[$shard] = self::groupsOf($mutants, $timed);
        }

        $grown = 1.0;

        do {
            [$shares, $split] = self::cutAt($groups, $timed, $capacity, $grown, $wall);
            $grown *= max(self::GROWTH, sqrt(count($shares) / $most));
        } while ($split && count($shares) > $most);

        return LeftoverShares::cut(
            $plan,
            ...$tails
                ? array_map(static fn(LeftoverShare $share): LeftoverShare => $share->runningItsTail(), $shares)
                : $shares,
        );
    }

    /**
     * The shares of every shard with each capacity grown this many times, and whether any shard has more than one.
     *
     * @param  array<int, list<array{LeftGroup, list<LeftGroup>}>> $groups
     * @param  array<string, float>                                $timed
     * @param  array<int, Seconds>                                 $capacity
     * @return array{list<LeftoverShare>, bool}
     */
    private static function cutAt(array $groups, array $timed, array $capacity, float $grown, StreamWall $wall): array
    {
        $shares = [];
        $split = false;

        foreach ($groups as $shard => $each) {
            $within = array_key_exists($shard, $capacity) ? $capacity[$shard]->seconds() : 0.0;
            $fits = max($within, self::LEAST) * $grown;
            $cut = self::sharesOf(ShardId::of($shard), self::fittingGroups($each, $fits, $wall), $timed, $fits, $wall);
            $split = $split || count($cut) > 1;
            $shares = [...$shares, ...$cut];
        }

        return [$shares, $split];
    }

    /**
     * Each source file's group where it fits a job alone, and its mutants
     * one by one where it does not, costliest first.
     *
     * @param  list<array{LeftGroup, list<LeftGroup>}> $groups
     * @return list<LeftGroup>
     */
    private static function fittingGroups(array $groups, float $capacity, StreamWall $wall): array
    {
        $fitting = [];

        foreach ($groups as [$file, $mutants]) {
            $fitting = $file->wall($wall) <= $capacity ? [...$fitting, $file] : [...$fitting, ...$mutants];
        }

        usort($fitting, static fn(LeftGroup $one, LeftGroup $other): int => $other->wall($wall) <=> $one->wall($wall));

        return $fitting;
    }

    /**
     * @param  list<LeftGroup>      $groups
     * @param  array<string, float> $timed
     * @return list<LeftoverShare>
     */
    private static function sharesOf(
        ShardId $shard,
        array $groups,
        array $timed,
        float $capacity,
        StreamWall $wall,
    ): array {
        $open = [[], [], []];
        $closed = [];

        foreach ($groups as $group) {
            $open = self::placed($open, $group, $timed, $capacity, $wall);

            if (count($open[0]) > self::OFFERED && count($open[2]) > self::OFFERED) {
                $closed[] = [$open[0][0], $open[2][0]];
                $open = [array_slice($open[0], 1), array_slice($open[1], 1), array_slice($open[2], 1)];
            }
        }

        foreach ($open[0] as $index => $price) {
            $closed[] = [$price, $open[2][$index]];
        }

        $cut = [];

        foreach ($closed as $index => [$price, $mutants]) {
            $cut[] = LeftoverShare::of($shard, $index + 1, ...array_merge(...$mutants))->predicting(
                Seconds::of($price->first()),
                Seconds::of($price->second()),
            );
        }

        return $cut;
    }

    /**
     * The mutants in groups of a source file each, each with its mutants in groups of their own.
     *
     * @param  list<array{LeftMutant, list<string>, LeftForks}> $mutants
     * @param  array<string, float>                             $timed
     * @return list<array{LeftGroup, list<LeftGroup>}>
     */
    private static function groupsOf(array $mutants, array $timed): array
    {
        $held = [];
        $covering = [];
        $forks = [];
        $alone = [];

        foreach ($mutants as [$mutant, $files, $priced]) {
            $file = $mutant->file()->value();
            $held[$file][] = $mutant;
            $covering[$file][] = $files;
            $forks[$file] = array_key_exists($file, $forks) ? $forks[$file]->and($priced) : $priced;
            $alone[$file][] = LeftGroup::of([$mutant], $files, $timed, $priced);
        }

        $groups = [];

        foreach ($held as $file => $each) {
            $groups[] = [LeftGroup::of($each, array_merge(...$covering[$file]), $timed, $forks[$file]), $alone[$file]];
        }

        return $groups;
    }

    /**
     * The open shares, the latest few, each's price, test files and
     * mutants, with a group placed: joined to the one it fits best, or in a
     * share of its own.
     *
     * @param  array{list<LeftShare>, list<array<string, bool>>, list<list<list<LeftMutant>>>} $shares
     * @param  array<string, float>                                                            $timed
     * @return array{list<LeftShare>, list<array<string, bool>>, list<list<list<LeftMutant>>>}
     */
    private static function placed(
        array $shares,
        LeftGroup $group,
        array $timed,
        float $capacity,
        StreamWall $wall,
    ): array {
        [$prices, $files, $mutants] = $shares;
        $into = self::fitting($prices, $files, $group, $timed, $capacity, $wall);

        if ($into instanceof NotGiven) {
            return [
                [...$prices, LeftShare::of($group, $wall)],
                [...$files, $group->files()],
                [...$mutants, [$group->mutants()]],
            ];
        }

        $prices[$into] = $prices[$into]->with($group, LeftGroup::addedTo($files[$into], $group, $timed));
        $files[$into] += $group->files();
        $mutants[$into][] = $group->mutants();

        return [array_values($prices), $files, $mutants];
    }

    /**
     * The open share a group adds the fewest seconds to that it still fits,
     * the first of those; none where it fits none, or no job alone.
     *
     * @param array<int, LeftShare>           $prices each share's price
     * @param array<int, array<string, bool>> $files  each share's test files
     * @param array<string, float>      $timed
     */
    private static function fitting(
        array $prices,
        array $files,
        LeftGroup $group,
        array $timed,
        float $capacity,
        StreamWall $wall,
    ): int|NotGiven {
        $into = NotGiven::value();
        $least = 0.0;

        if ($group->wall($wall) > $capacity) {
            return $into;
        }

        foreach ($prices as $index => $price) {
            $joined = $price->with($group, LeftGroup::addedTo($files[$index], $group, $timed))->wall();
            $added = $joined - $price->wall();

            if ($joined <= $capacity && ($into instanceof NotGiven || $added < $least)) {
                $into = $index;
                $least = $added;
            }
        }

        return $into;
    }
}
