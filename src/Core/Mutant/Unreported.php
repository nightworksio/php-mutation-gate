<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/**
 * Something about a mutant its runner does not report, such as the line
 * Infection's mutant ends on, a reason for a mutant that has a result, or
 * the rejection of a mutant no static analyser killed.
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

    public static function rejection(): self
    {
        return new self();
    }
}
