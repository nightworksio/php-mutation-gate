<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/**
 * What a mutant changed: the mutator that made it, by the runner's full name
 * for it (Pest's mutator class, whose short name several share), the family
 * of that mutator, and the unified diff of the change.
 */
final readonly class Mutation
{
    private function __construct(private string $mutator, private MutatorFamily $family, private string $diff) {}

    public static function of(string $mutator, MutatorFamily $family, string $diff): self
    {
        return new self($mutator, $family, $diff);
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
}
