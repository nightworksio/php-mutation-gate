<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Exhaustion;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * What an unmutated control found (see Control): its tests passed, in the
 * seconds the runner timed them; a test failed; they ran out of its limit;
 * or it never ran, and why.
 */
final readonly class ControlRun
{
    /** Why a control run as a mutant that changes nothing never ran, where its run gives no reason. */
    public const string NO_ANSWER = 'its run came to no answer';

    private function __construct(
        private ControlEnd $end,
        private Seconds|Unmeasured $took,
        private string|NotGiven $why,
        private MemoryCap|NotGiven $peak = new NotGiven(),
    ) {
    }

    /** Its tests passed, taking this long, where the runner timed them. */
    public static function passed(Seconds|Unmeasured $took): self
    {
        return new self(ControlEnd::Passed, $took, NotGiven::value());
    }

    public static function failed(): self
    {
        return new self(ControlEnd::Failed, Unmeasured::duration(), NotGiven::value());
    }

    /** Its process ran out of the memory cap. */
    public static function outOfMemory(): self
    {
        return new self(ControlEnd::OutOfMemory, Unmeasured::duration(), NotGiven::value());
    }

    public static function ranOut(): self
    {
        return new self(ControlEnd::RanOut, Unmeasured::duration(), NotGiven::value());
    }

    /** It never ran, for this reason. */
    public static function unrun(string $why): self
    {
        return new self(ControlEnd::Unrun, Unmeasured::duration(), $why);
    }

    /**
     * What a control found that a runner ran as a mutant that changes
     * nothing: passed where it survived, failed where its tests failed or
     * errored, out of memory where its process ran out of the cap, ran out
     * where it timed out,
     * and never run, for the reason its run gives, otherwise.
     */
    public static function asMutant(Mutant $unchanged): self
    {
        $reason = $unchanged->reason();

        return match ($unchanged->status()) {
            MutantStatus::Survived => self::passed($unchanged->duration()),
            MutantStatus::Killed,
            MutantStatus::KilledByStaticAnalysis,
            MutantStatus::Errored => self::failed(),
            MutantStatus::OutOfMemory => self::outOfMemory(),
            MutantStatus::TimedOut,
            MutantStatus::Skipped => self::ranOut(),
            MutantStatus::Uncovered,
            MutantStatus::Unjudged,
            MutantStatus::IgnoredByMarker => self::unrun($reason instanceof Reason ? $reason->text() : self::NO_ANSWER),
        };
    }

    /**
     * What a control found that ran as a process of its own, by how the
     * process ended: ran out where it was stopped at its limit, out of memory
     * where PHP says it ran out of its memory limit, passed, in the time it
     * took, where it succeeded, and failed otherwise.
     */
    public static function ofProcess(Ran $ran): self
    {
        return match (true) {
            $ran->wasStopped() => self::ranOut(),
            Exhaustion::in($ran->output()) instanceof MemoryCap => self::outOfMemory(),
            $ran->succeeded() => self::passed($ran->duration()),
            default => self::failed(),
        };
    }

    /** This run, whose processes held at most this much memory. */
    public function withPeak(MemoryCap $peak): self
    {
        return clone($this, ['peak' => $peak]);
    }

    /** The most memory its processes held, where the launcher measured it (see PeakLauncher). */
    public function peak(): MemoryCap|NotGiven
    {
        return $this->peak;
    }

    public function end(): ControlEnd
    {
        return $this->end;
    }

    /** How long its tests took, where they passed. */
    public function took(): Seconds|Unmeasured
    {
        return $this->took;
    }

    /** Why it never ran, where it did not. */
    public function why(): string|NotGiven
    {
        return $this->why;
    }
}
