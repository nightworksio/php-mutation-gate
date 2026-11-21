<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function is_int;

use NightWorksIO\MutationGate\Core\Config\CostMode;
use NightWorksIO\MutationGate\Core\Config\ShardTail;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How a cut decides its jobs' tail (ADR-0303): `shards.tail` under
 * `costs.mode`, the pool of jobs that run at once, where a job that runs its
 * own tail stops, and how much of that stop the cut fills (`shards.fill`). A
 * cut whose jobs run their tail and come to more than the pool is cut to the
 * pool instead, each job filled to that share of its stop, the rest left for
 * the gate's own steps no unit's cost holds and for costs the plan misjudged.
 *
 * @internal
 */
final readonly class TailRule
{
    /**
     * `shards.fill` where the config sets none: about the gate's own steps in
     * a job of eight minutes left free, which no unit's cost holds.
     */
    public const float FILL = 0.92;
    private function __construct(
        private ShardTail $tail,
        private CostMode $mode,
        private JobTail $jobs,
        private Seconds $stop,
        private float $fill,
    ) {
    }

    /**
     * @param Seconds $stop how long a job's gate runs before it stops, where it runs its own tail
     * @param float   $fill the share of that stop the cut fills each job with
     */
    public static function of(ShardTail $tail, CostMode $mode, JobTail $jobs, Seconds $stop, float $fill): self
    {
        return new self($tail, $mode, $jobs, $stop, $fill);
    }

    /** How many jobs run at once. */
    public function pool(): int
    {
        $pool = $this->jobs->pool();

        return is_int($pool) ? $pool : PHP_INT_MAX;
    }

    /** How much of its stop the cut fills a job that runs its own tail with. */
    public function filled(): Seconds
    {
        return Seconds::of($this->stop->seconds() * $this->fill);
    }

    /** Whether a cut of this many jobs is cut to the pool instead. */
    public function folds(int $jobs): bool
    {
        return $jobs > $this->pool() && $this->runs($jobs);
    }

    /** How a cut of this many jobs stops them. */
    public function job(int $jobs): JobTail
    {
        return $this->runs($jobs) ? $this->jobs->running() : $this->jobs;
    }

    private function runs(int $jobs): bool
    {
        return $this->tail->runs($jobs > $this->pool(), $this->mode);
    }
}
