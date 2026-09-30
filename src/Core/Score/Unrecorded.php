<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

/** Nothing recorded for a tree: no floor in the baseline, or no score read from the base. */
final readonly class Unrecorded
{
    public static function floor(): self
    {
        return new self();
    }
}
