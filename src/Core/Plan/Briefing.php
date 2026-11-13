<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Test\SuiteName;

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
        );
    }

    /** This briefing, for a run that makes mutants with the security mutators alone, as `--security` asks. */
    public function securityOnly(): self
    {
        return new self(
            $this->peak,
            $this->kind->securityOnly(),
            $this->ownScopeCoverage,
            $this->unchanged,
            $this->kills,
            $this->early,
            $this->waits,
        );
    }

    /** This briefing, for a run whose mutants one suite's tests alone judge, as `--suite` asks. */
    public function inSuite(SuiteName $suite): self
    {
        return new self(
            $this->peak,
            $this->kind->inSuite($suite),
            $this->ownScopeCoverage,
            $this->unchanged,
            $this->kills,
            $this->early,
            $this->waits,
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
        );
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
        );
    }

    /** This briefing, with the commit whose verdict stands for the plan that one names, where it names one. */
    public function standingAs(self $planned): self
    {
        return $planned->unchanged instanceof Unchanged ? $this->unchangedSince($planned->unchanged) : $this;
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
