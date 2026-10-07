<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
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
        private Seconds|Unmeasured $need,
    ) {
    }

    public static function killed(): self
    {
        return self::bare(MutantStatus::Killed, Unreported::reason());
    }

    public static function survived(): self
    {
        return self::bare(MutantStatus::Survived, Unreported::reason());
    }

    public static function timedOut(): self
    {
        return self::bare(MutantStatus::TimedOut, Unreported::reason());
    }

    /** Never run against the mutant, because its tests on their own run out of its limit. */
    public static function skipped(): self
    {
        return self::bare(MutantStatus::Skipped, Unreported::reason());
    }

    public static function unjudged(string $reason): self
    {
        return self::bare(MutantStatus::Unjudged, Reason::that($reason));
    }

    /** This outcome, of a run that took this long. */
    public function took(Seconds $duration): self
    {
        return new self($this->status, $this->reason, $duration, $this->limit, $this->need);
    }

    /** This outcome, of a run allowed this long. */
    public function within(Seconds $limit): self
    {
        return new self($this->status, $this->reason, $this->duration, $limit, $this->need);
    }

    /** This outcome, of tests that took this long on their own, unmutated (ADR-0008, decision 2). */
    public function needing(Seconds|Unmeasured $need): self
    {
        return new self($this->status, $this->reason, $this->duration, $this->limit, $need);
    }

    /** How long the tests took on their own, unmutated, where their run on their own timed them. */
    public function need(): Seconds|Unmeasured
    {
        return $this->need;
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

    /**
     * The mutant as this outcome judges it; where it ran out of time, with
     * the limit its run was allowed and the time its tests took on their
     * own, unmutated, which timeout triage weighs that limit against.
     */
    public function judging(Mutant $mutant): Mutant
    {
        $judged = Mutant::of(
            $mutant->id(),
            $mutant->nativeId(),
            $mutant->location(),
            $mutant->mutation(),
            $this->status,
            $this->duration,
        );

        if ($this->reason instanceof Reason || ! $this->status->ranOutOfTime()) {
            return $this->reason instanceof Reason ? $judged->because($this->reason) : $judged;
        }

        $limited = $this->limit instanceof Seconds ? $judged->withLimit($this->limit) : $judged;

        return $this->need instanceof Seconds ? $limited->withUnmutatedNeed($this->need) : $limited;
    }

    /** An outcome of this status and reason, with nothing timed. */
    private static function bare(MutantStatus $status, Reason|Unreported $reason): self
    {
        $none = Unmeasured::duration();

        return new self($status, $reason, $none, $none, $none);
    }
}
