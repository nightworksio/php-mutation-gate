<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

/**
 * The change that removes the node, which must be a statement: Pest removes
 * it, and Infection puts an empty statement in its place.
 */
final readonly class Removal
{
    private function __construct()
    {
    }

    public static function statement(): self
    {
        return new self();
    }
}
