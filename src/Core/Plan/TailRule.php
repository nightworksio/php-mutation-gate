<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function is_int;

use NightWorksIO\MutationGate\Core\Config\CostMode;
use NightWorksIO\MutationGate\Core\Config\ShardTail;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How a cut decides its jobs' tail (ADR-0303): `shards.tail` under
 * `costs.mode`, the pool of jobs that run at once, and where a job that
 * runs its own tail stops. A cut whose jobs run their tail and come to more
 * than the pool is cut to the pool instead, each job within that stop.
 *
 * @internal
 */
final readonly class TailRule
{
    private function __construct(
        private ShardTail $tail,
        private CostMode $mode,
        private JobTail $jobs,
        private Seconds $stop,
    ) {
    }

    /** @param Seconds $stop how long a job's gate runs before it stops, where it runs its own tail */
    public static function of(ShardTail $tail, CostMode $mode, JobTail $jobs, Seconds $stop): self
    {
        return new self($tail, $mode, $jobs, $stop);
    }

    /** How many jobs run at once. */
    public function pool(): int
    {
        $pool = $this->jobs->pool();

        return is_int($pool) ? $pool : PHP_INT_MAX;
    }

    /** How long a job's gate runs before it stops, where it runs its own tail. */
    public function stop(): Seconds
    {
        return $this->stop;
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
