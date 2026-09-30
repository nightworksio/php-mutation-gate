<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function rawurlencode;
use function sprintf;

/**
 * How far a test got, as the extension records it, a line for each: started,
 * then passed, failed, errored, or neither, such as skipped. A line is the
 * outcome, a space, and the test's id, encoded so a data set's name that
 * holds a space or a line break stays on its line.
 */
enum Outcome: string
{
    case Started = 'started';
    case Passed = 'passed';
    case Failed = 'failed';
    case Errored = 'errored';
    case Neither = 'neither';

    /** What parts a line: the outcome before it, the test after. */
    public const string SEPARATOR = ' ';

    /** The line that records a test's outcome. */
    public function line(string $test): string
    {
        return sprintf("%s%s%s\n", $this->value, self::SEPARATOR, rawurlencode($test));
    }

    /** Whether a test that ended so kills the mutant it ran against. */
    public function kills(): bool
    {
        return $this === self::Failed || $this === self::Errored;
    }
}
