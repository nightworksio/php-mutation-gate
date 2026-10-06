<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a shard measured: the time it spent mutating, from the end of its
 * opening run to its last mutant, which runner spent it, when, and the steps
 * its time went to (ADR-0016, decision 19).
 */
final readonly class Measurement
{
    private function __construct(
        private Seconds $spent,
        private string $runner,
        private Instant $at,
        private StepTimes $steps,
    ) {
    }

    public static function of(Seconds $spent, string $runner, Instant $at): self
    {
        return new self($spent, $runner, $at, StepTimes::none());
    }

    /** The same measurement, its time having gone to these steps. */
    public function withSteps(StepTimes $steps): self
    {
        return new self($this->spent, $this->runner, $this->at, $steps);
    }

    /** The steps the shard's time went to, each from when the shard began; none where it timed none. */
    public function steps(): StepTimes
    {
        return $this->steps;
    }

    public function spent(): Seconds
    {
        return $this->spent;
    }

    public function runner(): string
    {
        return $this->runner;
    }

    public function at(): Instant
    {
        return $this->at;
    }
}
