<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

/** How a test ended, as the extension records it: passed, failed, errored, or neither, such as skipped. */
enum Outcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Errored = 'errored';
    case Neither = 'neither';

    /** Whether a test that ended so kills the mutant it ran against. */
    public function kills(): bool
    {
        return $this === self::Failed || $this === self::Errored;
    }
}
