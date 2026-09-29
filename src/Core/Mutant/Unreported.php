<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/**
 * Something about a mutant its runner does not report, such as the line
 * Infection's mutant ends on, or a reason for a mutant that has a result.
 */
final readonly class Unreported
{
    public static function line(): self
    {
        return new self();
    }

    public static function reason(): self
    {
        return new self();
    }
}
