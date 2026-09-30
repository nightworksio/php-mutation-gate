<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cluster;

/** A mutant whose change is not found among its lines' tokens, or runs past one statement. */
final readonly class Unplaced
{
    private function __construct()
    {
    }

    public static function mutant(): self
    {
        return new self();
    }
}
