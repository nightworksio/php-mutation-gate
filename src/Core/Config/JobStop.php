<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function max;
use function min;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * When a CI job's gate stops (ADR-0196): `shards.target`, the wall time the
 * job is cut to fit, less the setup before the gate started, the wind-down
 * the gate takes once it stops and `shards.margin`; no later than
 * `shards.jobLimit` after the gate started, where the config caps it. A
 * config that sets `shards.jobLimit` and no `shards.target` stops at the
 * limit alone.
 */
final readonly class JobStop
{
    /** The seconds a job's gate is taken to go on for once it stops, where no run measured it. */
    public const int WIND_DOWN_SECONDS = 35;

    public function __construct(
        private Seconds $target,
        private Seconds $margin,
        private Seconds|Absent $jobLimit,
        private bool $limitAlone = false,
    ) {
    }

    /** `shards.target`, or ten minutes where the config sets none. */
    public function target(): Seconds
    {
        return $this->target;
    }

    /** `shards.margin`: how long the gate keeps clear of the target past its own wind-down. */
    public function margin(): Seconds
    {
        return $this->margin;
    }

    /** `shards.jobLimit`: the longest the gate runs, where the config caps it; none where the target alone does. */
    public function jobLimit(): Seconds|Absent
    {
        return $this->jobLimit;
    }

    /** How long the gate runs before it stops after this setup, taking this wind-down; none where none is left. */
    public function after(Seconds $setup, Seconds $windDown): Seconds
    {
        $left = max(
            0.0,
            $this->target->seconds() - $setup->seconds() - $windDown->seconds() - $this->margin->seconds(),
        );

        return match (true) {
            ! $this->jobLimit instanceof Seconds => Seconds::of($left),
            $this->limitAlone => $this->jobLimit,
            default => Seconds::of(min($left, $this->jobLimit->seconds())),
        };
    }

    /** How long the gate runs before it stops, where no job measured the wind-down. */
    public function unmeasured(Seconds $setup): Seconds
    {
        return $this->after($setup, Seconds::of(self::WIND_DOWN_SECONDS));
    }
}
