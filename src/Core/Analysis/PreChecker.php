<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\Runner\ProcessCount;

/**
 * What checks a run's mutants with static analysis before their tests,
 * where that pays (ADR-0020, decisions 11 and 12). A runner asks it once
 * for the mutants it made, before any runs, and runs none it rejects: each
 * is killed by static analysis.
 */
interface PreChecker
{
    /**
     * Which of these mutants static analysis rejects, each by the finding
     * that rejects it, checked side by side up to this many at once. A mutant
     * it does not check, or passes, is left to its tests.
     */
    public function rejected(PreCheckables $mutants, ProcessCount $side): Rejections;
}
