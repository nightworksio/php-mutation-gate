<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function preg_match;
use function str_contains;

/**
 * What PHPUnit prints where its PHP process ends mid-test (ADR-0004, decision
 * 9): after a fatal error, shown or hidden, or `exit`, and, where
 * `display_errors` was off as the test started, that it hid the error.
 */
final readonly class PrematureEnd
{
    /** What PHPUnit prints after any end of its process mid-test: a fatal error, shown or hidden, or `exit`. */
    private const string ENDED = '/Fatal error: Premature end of PHP process when running [^\n]+\.$/m';

    /** What PHPUnit prints instead where `display_errors` was off as the test started. */
    private const string HIDDEN
        = "Fatal error: Premature end of PHPUnit's PHP process. Use display_errors=On to see the error message.";

    /**
     * Whether this output says a process ended mid-test where PHP's errors
     * were visibly hidden: as PHPUnit says it hid them, or where the
     * project's config hides them from what the gate reads and PHPUnit says
     * the process ended.
     */
    public static function hidingIn(string $output, bool $configuredToHide): bool
    {
        return str_contains($output, self::HIDDEN) || ($configuredToHide && preg_match(self::ENDED, $output) === 1);
    }
}
