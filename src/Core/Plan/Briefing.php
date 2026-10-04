<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

/**
 * What a plan tells every shard about how to run its units, and the verdict
 * about how they ran: the most memory the unmutated suite's largest process
 * held in the coverage run the plan was made from, which every shard's memory
 * triage weighs its mutants against (ADR-0004, decision 9), how much of the
 * kill matrix the run records (ADR-0014, decision 7), and whether the run
 * makes mutants with the security mutators alone and holds only the security
 * sets (ADR-0021, decision 20).
 */
final readonly class Briefing
{
    private function __construct(
        private MemoryCap|NotGiven $peak,
        private MatrixKind $matrix,
        private bool $securityOnly,
    ) {
    }

    /** No peak measured, first killers recorded, and mutants made with every mutator the run turns on. */
    public static function standard(): self
    {
        return new self(NotGiven::value(), MatrixKind::FirstKiller, securityOnly: false);
    }

    /** This briefing, with the most memory the unmutated suite's largest process held, or none measured. */
    public function weighing(MemoryCap|NotGiven $peak): self
    {
        return clone($this, ['peak' => $peak]);
    }

    /** This briefing, recording this much of the kill matrix. */
    public function recording(MatrixKind $matrix): self
    {
        return clone($this, ['matrix' => $matrix]);
    }

    /** This briefing, for a run that makes mutants with the security mutators alone, as `--security` asks. */
    public function securityOnly(): self
    {
        return clone($this, ['securityOnly' => true]);
    }

    /** The most memory the unmutated suite's largest process held; none where the plan did not measure it. */
    public function peak(): MemoryCap|NotGiven
    {
        return $this->peak;
    }

    /** How much of the kill matrix the run records. */
    public function matrix(): MatrixKind
    {
        return $this->matrix;
    }

    /** Whether the run makes mutants with the security mutators alone, and holds only the security sets. */
    public function isSecurityOnly(): bool
    {
        return $this->securityOnly;
    }
}
