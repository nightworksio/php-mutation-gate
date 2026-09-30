<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Baseline;

/** A tree's floor that did not go down, or went down with its reason. */
final readonly class Kept
{
    public static function floor(): self
    {
        return new self();
    }
}
