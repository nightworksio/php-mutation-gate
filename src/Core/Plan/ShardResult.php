<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * What one shard left for the verdict: the plan it followed, its units with
 * their keys, every mutant's record or the runner's cannot judge, what it
 * measured, the mutants that gave two answers, the held units whose holding
 * tests miss lines of them, which it did not mutate, what it warns of, the
 * units its budget ran out before, what static analysis's checks of its
 * survivors came to, and the survivor it stopped on once the run could not
 * pass, where it did.
 *
 * @internal
 */
final readonly class ShardResult
{
    private function __construct(
        private Digest $plan,
        private ShardId $shard,
        private Keys $units,
        private MutationResult|CannotJudge $outcome,
        private Measurement $measured,
        private MutantIds $flaky,
        private HeldChecks $held,
        private Warnings $warnings,
        private Units $unjudged,
        private SurvivorChecks $checks,
        private Doomed|Undoomed $doomed,
    ) {
    }

    public static function of(
        Digest $plan,
        ShardId $shard,
        Keys $units,
        MutationResult|CannotJudge $outcome,
        Measurement $measured,
    ): self {
        return new self(
            $plan,
            $shard,
            $units,
            $outcome,
            $measured,
            MutantIds::none(),
            HeldChecks::none(),
            Warnings::none(),
            Units::none(),
            SurvivorChecks::none(),
            Undoomed::run(),
        );
    }

    /** This result, with the survivors a second run killed, which are flaky (ADR-0008). */
    public function withFlaky(MutantIds $flaky): self
    {
        return new self(
            $this->plan,
            $this->shard,
            $this->units,
            $this->outcome,
            $this->measured,
            $flaky,
            $this->held,
            $this->warnings,
            $this->unjudged,
            $this->checks,
            $this->doomed,
        );
    }

    /**
     * This result, with what each held unit's holding tests found: the units
     * they miss lines of, which it did not mutate, and those they cover, each
     * with the tests of theirs that run it.
     */
    public function withHeld(HeldChecks $held): self
    {
        return new self(
            $this->plan,
            $this->shard,
            $this->units,
            $this->outcome,
            $this->measured,
            $this->flaky,
            $held,
            $this->warnings,
            $this->unjudged,
            $this->checks,
            $this->doomed,
        );
    }

    /** This result, with what the shard warns of, which judges nothing. */
    public function withWarnings(Warnings $warnings): self
    {
        return new self(
            $this->plan,
            $this->shard,
            $this->units,
            $this->outcome,
            $this->measured,
            $this->flaky,
            $this->held,
            $warnings,
            $this->unjudged,
            $this->checks,
            $this->doomed,
        );
    }

    /**
     * This result, with the units the budget ran out before, or its doom
     * left, which it never started (ADR-0008).
     */
    public function withUnjudged(Units $unjudged): self
    {
        return new self(
            $this->plan,
            $this->shard,
            $this->units,
            $this->outcome,
            $this->measured,
            $this->flaky,
            $this->held,
            $this->warnings,
            $unjudged,
            $this->checks,
            $this->doomed,
        );
    }

    /** This result, with what static analysis's checks of its survivors came to (ADR-0020). */
    public function withChecks(SurvivorChecks $checks): self
    {
        return new self(
            $this->plan,
            $this->shard,
            $this->units,
            $this->outcome,
            $this->measured,
            $this->flaky,
            $this->held,
            $this->warnings,
            $this->unjudged,
            $checks,
            $this->doomed,
        );
    }

    /**
     * This result, with the survivor that made the run certain to fail, on
     * which the shard stopped, leaving the units it did not run unjudged
     * (ADR-0008).
     */
    public function withDoomed(Doomed|Undoomed $doomed): self
    {
        return new self(
            $this->plan,
            $this->shard,
            $this->units,
            $this->outcome,
            $this->measured,
            $this->flaky,
            $this->held,
            $this->warnings,
            $this->unjudged,
            $this->checks,
            $doomed,
        );
    }

    /** The digest of the plan the shard followed. */
    public function plan(): Digest
    {
        return $this->plan;
    }

    public function shard(): ShardId
    {
        return $this->shard;
    }

    /** The shard's units, each with its content key or why it has none. */
    public function units(): Keys
    {
        return $this->units;
    }

    /** Every mutant the runner reported, or why it could not judge, with its output. */
    public function outcome(): MutationResult|CannotJudge
    {
        return $this->outcome;
    }

    public function measured(): Measurement
    {
        return $this->measured;
    }

    /** The mutants that survived once and were killed when run again. */
    public function flaky(): MutantIds
    {
        return $this->flaky;
    }

    /** The held units whose holding tests miss lines of them, and those they cover, with the tests that run each. */
    public function held(): HeldChecks
    {
        return $this->held;
    }

    /** What the shard warns of. */
    public function warnings(): Warnings
    {
        return $this->warnings;
    }

    /** The units the budget ran out before, or the shard's doom left, which it never started. */
    public function unjudged(): Units
    {
        return $this->unjudged;
    }

    /** What static analysis's checks of the shard's survivors came to. */
    public function checks(): SurvivorChecks
    {
        return $this->checks;
    }

    /** The survivor the shard stopped on once the run could not pass, where it did. */
    public function doomed(): Doomed|Undoomed
    {
        return $this->doomed;
    }
}
