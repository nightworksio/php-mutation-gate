<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/**
 * The mutator set this repository ships, `default`: the gate's own engine
 * makes its mutants with it and counts a plan's mutants with it (ADR-0023,
 * decision 8), and Pest and Infection apply their own mutators in its place,
 * so it is on in every run and a config never turns it on (ADR-0021).
 */
final readonly class ShippedMutatorSet
{
    /** The name the registry holds it by. */
    public const string NAME = 'default';
}
