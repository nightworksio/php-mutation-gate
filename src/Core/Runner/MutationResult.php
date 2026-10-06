<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * What a mutation run reported: every mutant it has a record of, and how many
 * it skipped without one. Infection skips a mutant whose covering tests take
 * longer than its timeout, and names it only in a count; those mutants are
 * unjudged, with no id. It may warn of what the run did besides, and name
 * the steps its time went to, where the runner times its own (ADR-0016,
 * decision 19).
 */
final readonly class MutationResult
{
    private function __construct(
        private Mutants $mutants,
        private int $skipped,
        private Warnings $warnings,
        private StepTimes $steps,
    ) {
    }

    public static function of(Mutants $mutants, int $skipped): self
    {
        return new self($mutants, $skipped, Warnings::none(), StepTimes::none());
    }

    /** This result with these mutants in place of its own: what it skipped, warned of and timed kept. */
    public function withMutants(Mutants $mutants): self
    {
        return new self($mutants, $this->skipped, $this->warnings, $this->steps);
    }

    /** This result, warning of these as well, after what it warned of already. */
    public function withWarnings(Warnings $warnings): self
    {
        return new self($this->mutants, $this->skipped, $this->warnings->and($warnings), $this->steps);
    }

    /** What the run warns of beside its mutants, such as a warm worker its guard dropped (ADR-0023, decision 13). */
    public function warnings(): Warnings
    {
        return $this->warnings;
    }

    /** This result, its time having gone to these steps as well, after those it named already. */
    public function withSteps(StepTimes $steps): self
    {
        return new self($this->mutants, $this->skipped, $this->warnings, $this->steps->and($steps));
    }

    /** This result, its time having gone to these steps first, before those it named already. */
    public function withStepsBefore(StepTimes $earlier): self
    {
        return new self($this->mutants, $this->skipped, $this->warnings, $earlier->and($this->steps));
    }

    /** The steps the run's time went to, timed from when it began; none where the runner times none. */
    public function steps(): StepTimes
    {
        return $this->steps;
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
