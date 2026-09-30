<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

/**
 * The change that removes the node. Pest removes a statement, and Infection
 * puts an empty statement in its place. Both remove an item of a list, such
 * as an array's.
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

    public static function item(): self
    {
        return new self();
    }
}
