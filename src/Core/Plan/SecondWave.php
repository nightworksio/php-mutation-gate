<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_key_exists;
use function array_keys;
use function array_values;
use function count;
use function is_float;
use function is_int;
use function max;

use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Cost\JobsLearned;
use NightWorksIO\MutationGate\Core\Cost\JobTimes;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The shares a second wave is cut into, as the plan's jobs stop (ADR-0303):
 * where they ran their own tail, at most the pool's shares, each its job's
 * limit, each job running its own tail; where they stopped at their target,
 * shares of that stop, folded, where they come to more than the pool and
 * `shards.tail` runs such a wave under `costs.mode`, into at most the pool's
 * shares whose jobs run their own tail to their limit.
 *
 * @internal
 */
final readonly class SecondWave
{
    /**
     * @param array<int, list<array{LeftMutant, list<string>, LeftForks}>> $left    each shard's left mutants, by its
     *                                                                              number
     * @param array<string, float>                                         $timed   the seconds each test file's tests
     *                                                                              took together, by the file
     * @param array<int, Seconds>                                          $started how long each shard took to start
     *                                                                              judging, by its number
     * @param array<int, JobTimes>                                         $jobs    what each shard's job of the first
     *                                                                              wave measured, by its number
     */
    public static function cut(
        Digest $plan,
        array $left,
        array $timed,
        array $started,
        PlanLearned $learned,
        Shards $settings,
        StreamWall $wall,
        array $jobs = [],
    ): LeftoverShares {
        $walls = self::walls($left, $wall, $jobs);
        $tail = $learned->tail();
        $pool = $tail->pool();
        $most = is_int($pool) ? $pool : LeftoverCut::MATRIX_MOST;
        $shares = LeftoverCut::of(
            $plan,
            $left,
            $timed,
            self::capacities($started, $learned->stop($settings)),
            $wall,
            $tail->runs() ? $most : LeftoverCut::MATRIX_MOST,
            $tail->runs(),
            $walls,
        );

        $outnumbers = is_int($pool) && count($shares->matrix()) > $pool;
        $folds = ! $tail->runs()
            && $tail->limit() instanceof Seconds
            && $settings->tail()->runs($outnumbers, $settings->costs()->mode())
            && $outnumbers;

        return $folds
            ? LeftoverCut::of(
                $plan,
                $left,
                $timed,
                self::capacities($started, $learned->tailing($tail->running())->stop($settings)),
                $wall,
                $most,
                tails: true,
                walls: $walls,
            )
            : $shares;
    }

    /**
     * The wall each shard's shares are priced at: the second pass's factor
     * its own job of the first wave measured; else the median of those the
     * run's jobs measured; else this wall's, the factor the plan learned, or
     * 1.0 where it learned none.
     *
     * @param  array<int, list<array{LeftMutant, list<string>, LeftForks}>> $left
     * @param  array<int, JobTimes>                                         $jobs
     * @return array<int, StreamWall>
     */
    private static function walls(array $left, StreamWall $wall, array $jobs): array
    {
        $median = JobsLearned::of(...array_values($jobs))->passTwo();
        $run = is_float($median) ? $wall->passingTwiceAt($median) : $wall;
        $walls = [];

        foreach (array_keys($left) as $shard) {
            $own = array_key_exists($shard, $jobs) ? $jobs[$shard]->passTwo() : NotGiven::value();
            $walls[$shard] = is_float($own) ? $wall->passingTwiceAt($own) : $run;
        }

        return $walls;
    }

    /**
     * The seconds a job of this limit has to judge each shard's mutants in,
     * as long as the shard took to start judging is spent first.
     *
     * @param  array<int, Seconds> $started
     * @return array<int, Seconds>
     */
    private static function capacities(array $started, Seconds $limit): array
    {
        $capacity = [];

        foreach ($started as $number => $since) {
            $capacity[$number] = Seconds::of(max(0.0, $limit->seconds() - $since->seconds()));
        }

        return $capacity;
    }
}
