<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

/** A verdict the flows gave no timings, so it says nothing of what its run took or cost. */
final readonly class Untimed
{
    public static function run(): self
    {
        return new self();
    }
}
