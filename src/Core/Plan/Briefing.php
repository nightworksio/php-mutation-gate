<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Hold\MeasuredHolds;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

/**
 * What a plan tells every shard about how to run its units, and the verdict
 * about how they ran: the most memory the unmutated suite's largest process
 * held in the coverage run the plan was made from, which every shard's memory
 * triage weighs its mutants against (ADR-0004), the kind of run
 * it is ({@see RunProfile}), whether its coverage map was measured against
 * the map its own scope keeps (ADR-0023), and, for a plan that
 * runs nothing because nothing the gate judges changed, the commit whose
 * verdict stands ({@see Unchanged}), and what it learned from the ledgers'
 * kills ({@see KillsLearned}).
 */
final readonly class Briefing
{
    private function __construct(
        private MemoryCap|NotGiven $peak,
        private RunProfile $kind,
        private bool $ownScopeCoverage,
        private Unchanged|NotGiven $unchanged,
        private KillsLearned $kills,
        private bool $early = false,
        private bool $waits = false,
        private MeasuredHolds|NotGiven $held = new NotGiven(),
    ) {
    }

    /**
     * No peak measured, first killers recorded, and mutants made with every
     * mutator the run turns on, judged by every test.
     */
    public static function standard(): self
    {
        return new self(
            NotGiven::value(),
            RunProfile::standard(),
            ownScopeCoverage: false,
            unchanged: NotGiven::value(),
            kills: KillsLearned::none(),
        );
    }

    /** This briefing, with the most memory the unmutated suite's largest process held, or none measured. */
    public function weighing(MemoryCap|NotGiven $peak): self
    {
        return new self(
            $peak,
            $this->kind,
            $this->ownScopeCoverage,
            $this->unchanged,
            $this->kills,
            $this->early,
            $this->waits,
            $this->held,
        );
    }

    /** This briefing, for a run of this kind. */
    public function ofKind(RunProfile $kind): self
    {
        return new self(
            $this->peak,
            $kind,
            $this->ownScopeCoverage,
            $this->unchanged,
            $this->kills,
            $this->early,
            $this->waits,
            $this->held,
        );
    }

    /** This briefing, recording this much of the kill matrix. */
    public function recording(MatrixKind $matrix): self
    {
        return new self(
            $this->peak,
            $this->kind->recording($matrix),
            $this->ownScopeCoverage,
            $this->unchanged,
            $this->kills,
            $this->early,
            $this->waits,
            $this->held,
        );
    }



    /**
     * This briefing, for a run whose coverage map was measured against the
     * map its own scope keeps, which its own code could have written, so its
     * verdict counts as one that used its own scope (ADR-0007).
     */
    public function onOwnScopeCoverage(): self
    {
        return new self(
            $this->peak,
            $this->kind,
            ownScopeCoverage: true,
            unchanged: $this->unchanged,
            kills: $this->kills,
            early: $this->early,
            waits: $this->waits,
            held: $this->held,
        );
    }

    /**
     * This briefing, for a full split-stream run planned without a fresh
     * map: it takes no proof, its streams record the lines each test
     * executes, and its verdict keys by the map they recorded (ADR-0135).
     */
    public function plannedEarly(): self
    {
        return new self(
            $this->peak,
            $this->kind,
            $this->ownScopeCoverage,
            $this->unchanged,
            $this->kills,
            early: true,
            waits: $this->waits,
            held: $this->held,
        );
    }

    /**
     * This briefing, for a plan that plans nothing because the run cannot
     * start without a fresh map and was asked to plan early alone: a caller
     * that hands a fresh map runs it (ADR-0135).
     */
    public function waitingForAMap(): self
    {
        return new self(
            $this->peak,
            $this->kind,
            $this->ownScopeCoverage,
            $this->unchanged,
            $this->kills,
            $this->early,
            waits: true,
            held: $this->held,
        );
    }

    /**
     * This briefing, telling every shard what the plan measured of the held
     * units' holding tests, run alone under coverage once (ADR-0161).
     */
    public function measuring(MeasuredHolds $held): self
    {
        return new self(
            $this->peak,
            $this->kind,
            $this->ownScopeCoverage,
            $this->unchanged,
            $this->kills,
            $this->early,
            $this->waits,
            $held,
        );
    }

    /** What the plan measured of the held units' holding tests; nothing where each shard measures its own. */
    public function held(): MeasuredHolds|NotGiven
    {
        return $this->held;
    }

    /** Whether the plan plans nothing, leaving the run to a caller that hands a fresh map (ADR-0135). */
    public function waits(): bool
    {
        return $this->waits;
    }

    /** Whether the run was planned without a fresh map (ADR-0135). */
    public function isEarly(): bool
    {
        return $this->early;
    }

    /** This briefing, telling its shards what the plan learned from the ledgers' kills. */
    public function learning(KillsLearned $kills): self
    {
        return new self(
            $this->peak,
            $this->kind,
            $this->ownScopeCoverage,
            $this->unchanged,
            $kills,
            $this->early,
            $this->waits,
            $this->held,
        );
    }

    /** What the plan learned from the ledgers' kills: the `timeouts.multiple` and the pace, where it learned each. */
    public function learned(): KillsLearned
    {
        return $this->kills;
    }

    /** This briefing, for a plan that runs nothing because the verdict of a commit that passed stands. */
    public function unchangedSince(Unchanged $unchanged): self
    {
        return new self(
            $this->peak,
            $this->kind,
            $this->ownScopeCoverage,
            $unchanged,
            $this->kills,
            $this->early,
            $this->waits,
            $this->held,
        );
    }

    /**
     * This briefing, with what the plan that one briefs settled: the commit
     * whose verdict stands, where it names one, and what it measured of the
     * held units, where it measured them (ADR-0161).
     */
    public function standingAs(self $planned): self
    {
        $standing = $planned->unchanged instanceof Unchanged ? $this->unchangedSince($planned->unchanged) : $this;

        return $planned->held instanceof MeasuredHolds ? $standing->measuring($planned->held) : $standing;
    }

    /** The commit whose verdict stands for a plan that runs nothing; none where the plan runs what it plans. */
    public function unchanged(): Unchanged|NotGiven
    {
        return $this->unchanged;
    }

    /** Whether the run's coverage map was measured against the map its own scope keeps. */
    public function isOnOwnScopeCoverage(): bool
    {
        return $this->ownScopeCoverage;
    }

    /** The kind of run the plan is for. */
    public function profile(): RunProfile
    {
        return $this->kind;
    }

    /** The most memory the unmutated suite's largest process held; none where the plan did not measure it. */
    public function peak(): MemoryCap|NotGiven
    {
        return $this->peak;
    }
}
