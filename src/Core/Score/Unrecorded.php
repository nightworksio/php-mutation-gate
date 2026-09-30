<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

/** A tree the baseline holds no floor for. */
final readonly class Unrecorded
{
    public static function floor(): self
    {
        return new self();
    }
}
