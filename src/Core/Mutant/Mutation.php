<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What a mutant changed: the mutator that made it, by the runner's full name
 * for it (Pest's mutator class, whose short name several share), the family
 * of that mutator, the unified diff of the change, and the sentence a
 * registered mutator gives its survivors in place of its family's, where it
 * has one (ADR-0021, decision 5).
 */
final readonly class Mutation
{
    private function __construct(
        private string $mutator,
        private MutatorFamily $family,
        private string $diff,
        private string|NotGiven $hint,
    ) {
    }

    public static function of(
        string $mutator,
        MutatorFamily $family,
        string $diff,
        string|NotGiven $hint = new NotGiven(),
    ): self {
        return new self($mutator, $family, $diff, $hint);
    }

    public function mutator(): string
    {
        return $this->mutator;
    }

    public function family(): MutatorFamily
    {
        return $this->family;
    }

    public function diff(): string
    {
        return $this->diff;
    }

    /** Its mutator's own sentence for a survivor; nothing where the family's is used. */
    public function hint(): string|NotGiven
    {
        return $this->hint;
    }
}
