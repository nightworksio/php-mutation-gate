<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

/** What is known of one covering test with one mutant in place (ADR-0014, decision 10). */
enum Outcome: string
{
    /** The test failed with the mutant in place. */
    case Killed = 'killed';
    /** The test ran and passed. */
    case Passed = 'passed';
    /** The run stopped at an earlier failure, or never ran the mutant. */
    case NotRun = 'not-run';
    /** A timeout's tests, a flaky mutant's, and a carried one's whose coverage moved since its proof. */
    case Unknown = 'unknown';

    /** Whether the test ran to an answer: it killed the mutant, or passed with it. */
    public function ran(): bool
    {
        return $this === self::Killed || $this === self::Passed;
    }
}
