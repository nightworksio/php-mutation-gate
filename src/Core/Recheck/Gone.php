<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Recheck;

/**
 * A survivor the run made no mutant with its id for again: its code, or the
 * code around it, changed so that the same change no longer exists.
 */
final readonly class Gone
{
    public static function value(): self
    {
        return new self();
    }
}
