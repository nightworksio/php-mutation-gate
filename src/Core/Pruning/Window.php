<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Pruning;

/**
 * How many of a mutator's newest judged mutants decide whether it is pruned
 * (ADR-0025, decision 2): `pruning.window`.
 */
final readonly class Window
{
    private function __construct(private int $mutants)
    {
    }

    public static function of(int $mutants): self
    {
        return new self($mutants);
    }

    public function mutants(): int
    {
        return $this->mutants;
    }
}
