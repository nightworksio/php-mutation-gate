<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function count;

use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * What one run of a mutant against the tests that read its value found, and
 * why it found nothing where it did; of a kill, the tests that killed it,
 * and, where none is named, how its run ended (ADR-0014, decision 17).
 */
final readonly class Outcome
{
    private function __construct(
        private MutantStatus $status,
        private Reason|Unreported $reason,
        private Seconds|Unmeasured $duration,
        private Seconds|Unmeasured $limit,
        private Seconds|Unmeasured $need,
        private TestIds $killers,
        private Ended|NotGiven $ended,
    ) {
    }

    /** Killed by these tests; where none is named, how the run ended is its evidence. */
    public static function killed(TestIds $killers, Ended $ended): self
    {
        return clone(self::bare(MutantStatus::Killed, Unreported::reason()), [
            'killers' => $killers,
            'ended' => count($killers) === 0 ? $ended : NotGiven::value(),
        ]);
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
        return clone($this, ['duration' => $duration]);
    }

    /** This outcome, of a run allowed this long. */
    public function within(Seconds $limit): self
    {
        return clone($this, ['limit' => $limit]);
    }

    /** This outcome, of tests that took this long on their own, unmutated (ADR-0008, decision 2). */
    public function needing(Seconds|Unmeasured $need): self
    {
        return clone($this, ['need' => $need]);
    }

    /** The evidence of its kill: how its run ended, where no test is named as its killer; none otherwise. */
    public function evidence(): Evidence
    {
        return $this->ended instanceof Ended ? Evidence::none()->withEnded($this->ended) : Evidence::none();
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
     * The mutant as this outcome judges it, killed by the tests that killed
     * it; where it ran out of time, with the limit its run was allowed and
     * the time its tests took on their own, unmutated, which timeout triage
     * weighs that limit against.
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
        )->killedBy($this->killers);

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

        return new self($status, $reason, $none, $none, $none, TestIds::none(), NotGiven::value());
    }
}
