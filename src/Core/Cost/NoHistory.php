<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

/** A run with no timings to measure savings from: it says so, never "saved 0" (ADR-0017, decision 11). */
final readonly class NoHistory
{
    public static function yet(): self
    {
        return new self();
    }
}
