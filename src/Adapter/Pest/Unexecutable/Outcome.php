<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** What one run of a mutant against the tests that read its value found, and why it found nothing where it did. */
final readonly class Outcome
{
    private function __construct(
        private MutantStatus $status,
        private Reason|Unreported $reason,
        private Seconds|Unmeasured $duration,
        private Seconds|Unmeasured $limit,
    ) {
    }

    public static function killed(): self
    {
        return new self(MutantStatus::Killed, Unreported::reason(), Unmeasured::duration(), Unmeasured::duration());
    }

    public static function survived(): self
    {
        return new self(MutantStatus::Survived, Unreported::reason(), Unmeasured::duration(), Unmeasured::duration());
    }

    public static function timedOut(): self
    {
        return new self(MutantStatus::TimedOut, Unreported::reason(), Unmeasured::duration(), Unmeasured::duration());
    }

    /** Never run against the mutant, because its tests on their own run out of its limit. */
    public static function skipped(): self
    {
        return new self(MutantStatus::Skipped, Unreported::reason(), Unmeasured::duration(), Unmeasured::duration());
    }

    public static function unjudged(string $reason): self
    {
        return new self(MutantStatus::Unjudged, Reason::that($reason), Unmeasured::duration(), Unmeasured::duration());
    }

    /** This outcome, of a run that took this long. */
    public function took(Seconds $duration): self
    {
        return new self($this->status, $this->reason, $duration, $this->limit);
    }

    /** This outcome, of a run allowed this long. */
    public function within(Seconds $limit): self
    {
        return new self($this->status, $this->reason, $this->duration, $limit);
    }

    /** How long the run was allowed, where it was timed by its tests. */
    public function limit(): Seconds|Unmeasured
    {
        return $this->limit;
    }

    public function duration(): Seconds|Unmeasured
    {
        return $this->duration;
    }

    public function status(): MutantStatus
    {
        return $this->status;
    }

    public function reason(): Reason|Unreported
    {
        return $this->reason;
    }

    /** Whether the mutant came through alive, so that more tests may still judge it. */
    public function leftAlive(): bool
    {
        return $this->status === MutantStatus::Survived;
    }
}
