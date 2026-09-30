<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

/** No change for a node the mutator looked at and leaves alone. */
final readonly class Unchanged
{
    private function __construct()
    {
    }

    public static function node(): self
    {
        return new self();
    }
}
