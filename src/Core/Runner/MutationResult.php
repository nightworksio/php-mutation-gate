<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * What a mutation run reported: every mutant it has a record of, and how many
 * it skipped without one. Infection skips a mutant whose covering tests take
 * longer than its timeout, and names it only in a count; those mutants are
 * unjudged, with no id. It may warn of what the run did besides.
 */
final readonly class MutationResult
{
    private function __construct(private Mutants $mutants, private int $skipped, private Warnings $warnings)
    {
    }

    public static function of(Mutants $mutants, int $skipped): self
    {
        return new self($mutants, $skipped, Warnings::none());
    }

    /** This result, warning of these as well, after what it warned of already. */
    public function withWarnings(Warnings $warnings): self
    {
        return new self($this->mutants, $this->skipped, $this->warnings->and($warnings));
    }

    /** What the run warns of beside its mutants, such as a warm worker its guard dropped (ADR-0023, decision 13). */
    public function warnings(): Warnings
    {
        return $this->warnings;
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
