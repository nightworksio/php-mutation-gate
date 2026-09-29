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
 * `CI_NODE_INDEX`, `BUILDKITE_PARALLEL_JOB` and `CIRCLE_NODE_INDEX` the CI
 * set, with 1 added to the two that count from 0. Where the CI also says how
 * many jobs it started, that must be the plan's count of shards, or the jobs
 * and the plan were not made for each other.
 */
final readonly class WhichShard
{
    /** Each variable that names a job, with the one that counts the jobs and what it counts from. */
    private const array NAMED_BY = [
        'SHARD' => ['', 1],
        'CI_NODE_INDEX' => ['CI_NODE_TOTAL', 1],
        'BUILDKITE_PARALLEL_JOB' => ['BUILDKITE_PARALLEL_JOB_COUNT', 0],
        'CIRCLE_NODE_INDEX' => ['CIRCLE_NODE_TOTAL', 0],
    ];

    private const string NUMBER = '/^\d+$/D';

    public static function in(Variables $variables, Plan $plan): ShardId|CannotJudge
    {
        foreach (self::NAMED_BY as $index => [$total, $from]) {
            if ($variables->has($index)) {
                return self::named($variables, $index, $total, $from, $plan);
            }
        }

        return CannotJudge::because(sprintf(
            'No shard is named. Pass --shard=<id>, or run under a CI that sets one of %s.',
            implode(', ', array_keys(self::NAMED_BY)),
        ));
    }

    private static function named(
        Variables $variables,
        string $index,
        string $total,
        int $from,
        Plan $plan,
    ): ShardId|CannotJudge {
        $value = $variables->valueOf($index);

        if (preg_match(self::NUMBER, $value) !== 1) {
            return CannotJudge::because(sprintf('%s is "%s", which is not a job number.', $index, $value));
        }

        if (! self::counted($variables, $total, $plan)) {
            return CannotJudge::because(sprintf(
                '%s is %s, and the plan holds %d shards. Plan with --shards=%s, so each job has a shard.',
                $total,
                $variables->valueOf($total),
                count($plan),
                $variables->valueOf($total),
            ));
        }

        return self::shardOf(intval($value) + 1 - $from, $plan);
    }

    /** Whether the jobs the CI started, where it says, are as many as the plan's shards. */
    private static function counted(Variables $variables, string $total, Plan $plan): bool
    {
        return ! $variables->has($total) || $variables->valueOf($total) === sprintf('%d', count($plan));
    }

    private static function shardOf(int $number, Plan $plan): ShardId|CannotJudge
    {
        $shard = $plan->shard(ShardId::of($number));

        return $shard instanceof CannotJudge ? $shard : $shard->id();
    }
}
