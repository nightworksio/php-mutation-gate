<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** What mutating one unit took a runner, as a finished shard measured it, and when. */
final readonly class Timing
{
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
