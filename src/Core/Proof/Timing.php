<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What mutating one unit took a runner, as a finished shard measured it,
 * when, and which release of the gate measured it: what one release of the
 * gate spends on a unit says nothing of another's.
 */
final readonly class Timing
{
    /**
     * The weight a unit's newest measurement takes in its smoothed timing;
     * the timing held takes the rest. It is the value that predicted the
     * gate's own third full CI run best from the two before it
     * (ADR-0006).
     */
    public const float NEWEST = 0.9;

    private function __construct(
        private Path $unit,
        private Seconds $seconds,
        private string $runner,
        private Instant $at,
        private GateRelease $gate,
    ) {
    }

    /** A timing no gate is recorded to have measured, as a ledger written before gates were recorded holds. */
    public static function of(Path $unit, Seconds $seconds, string $runner, Instant $at): self
    {
        return new self($unit, $seconds, $runner, $at, GateRelease::unrecorded());
    }

    /** The same timing, measured by this release of the gate. */
    public function measuredBy(GateRelease $gate): self
    {
        return new self($this->unit, $this->seconds, $this->runner, $this->at, $gate);
    }

    /**
     * This measurement, and another of the same unit in the same run, as
     * shards that each judge it by a part of the test files measure it
     * (ADR-0117): their seconds together, at the later of the two.
     */
    public function plus(self $other): self
    {
        return new self(
            $this->unit,
            Seconds::of($this->seconds->seconds() + $other->seconds->seconds()),
            $this->runner,
            $other->at->isAfter($this->at) ? $other->at : $this->at,
            $this->gate,
        );
    }

    /**
     * This measurement, smoothed over an earlier timing of the same unit:
     * weighing `timing.newest` itself, and the earlier timing the rest.
     */
    public function smoothedOver(self $earlier, TimingWeight|NotGiven $newest = new NotGiven()): self
    {
        $weight = $newest instanceof TimingWeight ? $newest : TimingWeight::of(self::NEWEST);
        $seconds = $weight->smoothed($this->seconds, $earlier->seconds);

        return new self($this->unit, $seconds, $this->runner, $this->at, $this->gate);
    }

    public function unit(): Path
    {
        return $this->unit;
    }

    public function seconds(): Seconds
    {
        return $this->seconds;
    }

    /** The runner that was measured, by the name its identity gives. */
    public function runner(): string
    {
        return $this->runner;
    }

    public function at(): Instant
    {
        return $this->at;
    }

    /** The release of the gate that measured it; unrecorded where a ledger recorded none. */
    public function gate(): GateRelease
    {
        return $this->gate;
    }
}
