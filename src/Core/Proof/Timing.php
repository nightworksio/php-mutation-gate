<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** What mutating one unit took a runner, as a finished shard measured it, and when. */
final readonly class Timing
{
    /**
     * The weight a unit's newest measurement takes in its smoothed timing;
     * the timing held takes the rest. It is the value that predicted the
     * gate's own third full CI run best from the two before it (ADR-0006,
     * decision 4).
     */
    public const float NEWEST = 0.9;

    private function __construct(
        private Path $unit,
        private Seconds $seconds,
        private string $runner,
        private Instant $at,
    ) {
    }

    public static function of(Path $unit, Seconds $seconds, string $runner, Instant $at): self
    {
        return new self($unit, $seconds, $runner, $at);
    }

    /**
     * This measurement, smoothed over an earlier timing of the same unit:
     * weighing `NEWEST` itself, and the earlier timing the rest.
     */
    public function smoothedOver(self $earlier): self
    {
        $seconds = self::NEWEST * $this->seconds->seconds() + (1 - self::NEWEST) * $earlier->seconds->seconds();

        return new self($this->unit, Seconds::of($seconds), $this->runner, $this->at);
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
}
