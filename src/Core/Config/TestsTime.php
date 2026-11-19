<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * `staticCheck.testsTime`: the time of a mutant's tests a check before them
 * is weighed against (ADR-0077).
 */
enum TestsTime: string
{
    /** Its covering tests' own time at the pace the ledgers learned kills take; their own time until one is learned. */
    case Learned = 'learned';

    /** Every covering test's own time, summed. */
    case Covering = 'covering';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Learned => 'CheckTiming::learnedTestsTime()',
            self::Covering => 'CheckTiming::coveringTestsTime()',
        };
    }
}
