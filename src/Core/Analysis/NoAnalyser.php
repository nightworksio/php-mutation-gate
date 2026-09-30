<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

/**
 * No static analyser checks the run's mutants: `staticCheck.tool` is `none`,
 * `auto` found none, or the one chosen cannot say what it is (ADR-0020,
 * decision 8).
 */
final readonly class NoAnalyser
{
    public static function configured(): self
    {
        return new self();
    }
}
