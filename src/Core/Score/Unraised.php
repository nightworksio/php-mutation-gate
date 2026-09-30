<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

/** A score that does not raise its baseline floor: no higher than it, no score at all, or an exempt tree. */
final readonly class Unraised
{
    public static function floor(): self
    {
        return new self();
    }
}
