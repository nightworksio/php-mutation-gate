<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a run takes: the wall time from its first job's start to its last
 * job's end, and the runner time every job spent together, measured where
 * the CI tells and estimated from `shards.setup` where it does not
 * (ADR-0016, decision 6, and ADR-0017, decision 12).
 */
final readonly class RunTime
{
    private function __construct(private Seconds $wall, private Seconds $runner, private bool $measured)
    {
    }

    /** A run time the CI's own job times gave. */
    public static function measured(Seconds $wall, Seconds $runner): self
    {
        return new self($wall, $runner, measured: true);
    }

    /** A run time from the gate's own timings, with each job's setup taken from `shards.setup`. */
    public static function estimated(Seconds $wall, Seconds $runner): self
    {
        return new self($wall, $runner, measured: false);
    }

    public function wall(): Seconds
    {
        return $this->wall;
    }

    public function runner(): Seconds
    {
        return $this->runner;
    }

    /** Whether the CI measured it, rather than the gate estimating its setup. */
    public function isMeasured(): bool
    {
        return $this->measured;
    }
}
