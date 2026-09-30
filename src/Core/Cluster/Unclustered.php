<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cluster;

/** A mutant that shares its cause with no other survivor, as far as the two rules of a cluster tell. */
final readonly class Unclustered
{
    private function __construct()
    {
    }

    public static function mutant(): self
    {
        return new self();
    }
}
