<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Mutant\Mutants;

/**
 * What a mutation run reported: every mutant it has a record of, and how many
 * it skipped without one. Infection skips a mutant whose covering tests take
 * longer than its timeout, and names it only in a count; those mutants are
 * unjudged, with no id.
 */
final readonly class MutationResult
{
    private function __construct(private Mutants $mutants, private int $skipped) {}

    public static function of(Mutants $mutants, int $skipped): self
    {
        return new self($mutants, $skipped);
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
