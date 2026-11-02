<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use function in_array;
use function rawurlencode;
use function sprintf;

/**
 * How far a test got, as the framework's recorder records it, a line for
 * each: started, then passed, failed, errored, or neither, such as skipped;
 * a test class whose `setUpBeforeClass` failed or errored, before any of its
 * tests started; or, once the run has ended, a test whose issue failed it
 * with no test failing or erroring (ADR-0014). A line is the outcome, a
 * space, and the test's id or the class's name, encoded so a data set's name
 * that holds a space or a line break stays on its line.
 *
 * @internal
 */
enum Outcome: string
{
    case Started = 'started';
    case Passed = 'passed';
    case Failed = 'failed';
    case Errored = 'errored';
    case Neither = 'neither';
    case ClassFailed = 'class-failed';
    case Issued = 'issued';

    /** What parts a line: the outcome before it, the test after. */
    public const string SEPARATOR = ' ';

    /** The line that records a test's outcome, or a class's. */
    public function line(string $test): string
    {
        return sprintf("%s%s%s\n", $this->value, self::SEPARATOR, rawurlencode($test));
    }

    /** Whether a test that ended so kills the mutant it ran against. */
    public function kills(): bool
    {
        return in_array($this, [self::Failed, self::Errored, self::Issued], strict: true);
    }
}
