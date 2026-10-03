<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use function array_keys;
use function count;
use function implode;
use function intval;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

use function preg_match;
use function sprintf;

/**
 * Which shard a job is, where no `--shard` names it: the first of `SHARD`,
 * `CI_NODE_INDEX`, `BUILDKITE_PARALLEL_JOB`, `CIRCLE_NODE_INDEX` and
 * `BITBUCKET_PARALLEL_STEP` the CI set, with 1 added to the three that count
 * from 0. Where the CI also says how many jobs it started, that must be the
 * plan's count of shards, or the jobs and the plan were not made for each
 * other.
 */
final readonly class WhichShard
{
    /** The variable a job the gate's own plan starts is named by, as a matrix sets it. */
    public const string VARIABLE = 'SHARD';

    /** Each variable that names a job, with what it counts from. */
    private const array NAMED_BY = [
        self::VARIABLE => 1,
        'CI_NODE_INDEX' => 1,
        'BUILDKITE_PARALLEL_JOB' => 0,
        'CIRCLE_NODE_INDEX' => 0,
        'BITBUCKET_PARALLEL_STEP' => 0,
    ];

    /** The variable that counts the jobs, for each variable that names a job and has one. */
    private const array COUNTED_BY = [
        'CI_NODE_INDEX' => 'CI_NODE_TOTAL',
        'BUILDKITE_PARALLEL_JOB' => 'BUILDKITE_PARALLEL_JOB_COUNT',
        'CIRCLE_NODE_INDEX' => 'CIRCLE_NODE_TOTAL',
        'BITBUCKET_PARALLEL_STEP' => 'BITBUCKET_PARALLEL_STEP_COUNT',
    ];

    private const string NUMBER = '/^\d+$/D';

    public static function in(Variables $variables, Plan $plan): ShardId|CannotJudge
    {
        foreach (self::NAMED_BY as $index => $from) {
            if ($variables->has($index)) {
                return self::named($variables, $index, $from, $plan);
            }
        }

        return CannotJudge::because(sprintf(
            'No shard is named. Pass --shard=<id>, or run under a CI that sets one of %s.',
            implode(', ', array_keys(self::NAMED_BY)),
        ));
    }

    private static function named(Variables $variables, string $index, int $from, Plan $plan): ShardId|CannotJudge
    {
        $value = $variables->valueOf($index);

        return preg_match(self::NUMBER, $value) === 1
            ? self::counted($variables, $index, intval($value) + 1 - $from, $plan)
            : CannotJudge::because(sprintf('%s is "%s", which is not a job number.', $index, $value));
    }

    /**
     * The shard of this number, where the jobs the CI started, if it says how many, are as many as the plan's
     * shards; or why not.
     */
    private static function counted(Variables $variables, string $index, int $number, Plan $plan): ShardId|CannotJudge
    {
        foreach (self::COUNTED_BY as $named => $total) {
            $miscounted = $variables->has($total) && $variables->valueOf($total) !== sprintf('%d', count($plan));

            if ($named === $index && $miscounted) {
                return CannotJudge::because(sprintf(
                    '%s is %s, and the plan holds %d shards. Plan with --shards=%s, so each job has a shard.',
                    $total,
                    $variables->valueOf($total),
                    count($plan),
                    $variables->valueOf($total),
                ));
            }
        }

        return self::shardOf($number, $plan);
    }

    private static function shardOf(int $number, Plan $plan): ShardId|CannotJudge
    {
        $shard = $plan->shard(ShardId::of($number));

        return $shard instanceof CannotJudge ? $shard : $shard->id();
    }
}
