<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Config\JobStop;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How a plan's jobs stop (ADR-0303): at their target, handing what they
 * left to a second wave, or running their own tail to their job's limit;
 * with that limit and how many jobs run at once, where the plan knew them.
 * A plan that knew neither, as one made before them, stops its jobs at
 * their target and cuts its second wave as before.
 */
final readonly class JobTail
{
    /** The seconds a CI job may run, where neither the config nor the CI says: GitHub's six hours. */
    public const int LIMIT_SECONDS = 21_600;

    private function __construct(private bool $runs, private Seconds|NotGiven $limit, private int|NotGiven $pool)
    {
    }

    /** Jobs that stop at their target, of a plan that knew no limit and no pool. */
    public static function unknown(): self
    {
        return new self(runs: false, limit: NotGiven::value(), pool: NotGiven::value());
    }

    /** Jobs that stop at their target, of this limit, this many running at once. */
    public static function of(Seconds $limit, int $pool): self
    {
        return new self(runs: false, limit: $limit, pool: $pool);
    }

    /** These jobs, running their own tail to their limit; stopping at their target where no limit is known. */
    public function running(): self
    {
        return new self($this->limit instanceof Seconds, $this->limit, $this->pool);
    }

    /** Whether the jobs run their own tail. */
    public function runs(): bool
    {
        return $this->runs;
    }

    /** The longest a job may run, its setup included; none where the plan knew none. */
    public function limit(): Seconds|NotGiven
    {
        return $this->limit;
    }

    /** How many jobs run at once, `shards.max`; none where the plan knew none. */
    public function pool(): int|NotGiven
    {
        return $this->pool;
    }

    /** How long a job's gate runs before it stops, after this setup, taking this wind-down. */
    public function stop(JobStop $stop, Seconds $setup, Seconds $windDown): Seconds
    {
        return $this->runs && $this->limit instanceof Seconds
            ? $stop->tailAfter($this->limit, $setup, $windDown)
            : $stop->after($setup, $windDown);
    }
}
