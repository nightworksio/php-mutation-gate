<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * What a mutation run reported: every mutant it has a record of, and how many
 * it skipped without one. Infection skips a mutant whose covering tests take
 * longer than its timeout, and names it only in a count; those mutants are
 * unjudged, with no id. It may warn of what the run did besides, and name
 * the steps its time went to, where the runner times its own (ADR-0016,
 * decision 19), and give the evidence of its kills, which judges nothing
 * (ADR-0014, decision 16).
 */
final readonly class MutationResult
{
    private function __construct(
        private Mutants $mutants,
        private int $skipped,
        private Warnings $warnings,
        private StepTimes $steps,
        private Evidences $evidence,
    ) {
    }

    public static function of(Mutants $mutants, int $skipped): self
    {
        return new self($mutants, $skipped, Warnings::none(), StepTimes::none(), Evidences::none());
    }

    /**
     * This result with these mutants in place of its own: what it skipped,
     * warned of and timed kept, and the evidence of each mutant given again
     * as it was, none of one another took the place of.
     */
    public function withMutants(Mutants $mutants): self
    {
        $evidence = $this->evidence->keptFor($this->mutants, $mutants);

        return new self($mutants, $this->skipped, $this->warnings, $this->steps, $evidence);
    }

    /** This result, warning of these as well, after what it warned of already. */
    public function withWarnings(Warnings $warnings): self
    {
        return new self($this->mutants, $this->skipped, $this->warnings->and($warnings), $this->steps, $this->evidence);
    }

    /** What the run warns of beside its mutants, such as a warm worker its guard dropped (ADR-0023, decision 13). */
    public function warnings(): Warnings
    {
        return $this->warnings;
    }

    /** This result, its time having gone to these steps as well, after those it named already. */
    public function withSteps(StepTimes $steps): self
    {
        return new self($this->mutants, $this->skipped, $this->warnings, $this->steps->and($steps), $this->evidence);
    }

    /** This result, its time having gone to these steps first, before those it named already. */
    public function withStepsBefore(StepTimes $earlier): self
    {
        return new self($this->mutants, $this->skipped, $this->warnings, $earlier->and($this->steps), $this->evidence);
    }

    /** The steps the run's time went to, timed from when it began; none where the runner times none. */
    public function steps(): StepTimes
    {
        return $this->steps;
    }

    /** This result, with the evidence of these kills as well, the later winning for a mutant both name. */
    public function withEvidence(Evidences $evidence): self
    {
        return new self($this->mutants, $this->skipped, $this->warnings, $this->steps, $this->evidence->and($evidence));
    }

    /** The evidence of its kills, by each mutant's id; none of a mutant the runner gave none of. */
    public function evidence(): Evidences
    {
        return $this->evidence;
    }

    public function mutants(): Mutants
    {
        return $this->mutants;
    }

    /** How many mutants were skipped with no record. */
    public function skipped(): int
    {
        return $this->skipped;
    }
}
