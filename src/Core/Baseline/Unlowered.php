<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Baseline;

/** A floor that was never lowered, or was raised since. */
final readonly class Unlowered
{
    public static function floor(): self
    {
        return new self();
    }
}
