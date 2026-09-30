<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** How the work is cut (ADR-0006): `shards.seconds`, `shards.max` and `costs.secondsPerLine`. */
final readonly class Shards
{
    public function __construct(
        private Seconds $seconds,
        private int $max,
        private Table $secondsPerLine,
        private Seconds|Absent $target,
        private Seconds $setup,
        private Price|Absent $perRunnerMinute,
    ) {
    }

    /** `shards.setup`: each shard's CI setup before the gate starts, which the gate cannot time (ADR-0013). */
    public function setup(): Seconds
    {
        return $this->setup;
    }

    /** How long one shard is cut to take. */
    public function seconds(): Seconds
    {
        return $this->seconds;
    }

    /** The most shards a plan cuts. */
    public function max(): int
    {
        return $this->max;
    }

    /** What a line costs before anything was measured, by path prefix. */
    public function secondsPerLine(): Table
    {
        return $this->secondsPerLine;
    }

    /** `shards.target`: the wall time the count is cut to fit, in place of `shards.seconds` (ADR-0013). */
    public function target(): Seconds|Absent
    {
        return $this->target;
    }

    /** `costs.perRunnerMinute`: what a runner-minute costs, where the team gives a rate (ADR-0016). */
    public function perRunnerMinute(): Price|Absent
    {
        return $this->perRunnerMinute;
    }
}
