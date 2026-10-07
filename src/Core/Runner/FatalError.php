<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function preg_match;

/**
 * PHP's own record of a fatal error in a process, as it prints it or logs it:
 * `Fatal error:` or `Parse error:` at the start of a line, after `PHP `
 * where it logs it, and after the time in brackets where it logs it to a
 * file. PHPUnit's `Fatal error: Premature end of PHP process`,
 * which it prints after an `exit` as after a fatal error, is PHPUnit's word,
 * not PHP's, and is none (ADR-0014, decision 17).
 */
final readonly class FatalError
{
    /** PHP's fatal or parse error at the start of a line, and not PHPUnit's word for a process that ended mid-test. */
    private const string RECORDED = '/^(?:\[[^\]\n]*\] )?(?:PHP )?(?:Fatal|Parse) error:\s+(?!Premature end of PHP)/m';

    /** Whether this text holds PHP's record of a fatal error. */
    public static function in(string $text): bool
    {
        return preg_match(self::RECORDED, $text) === 1;
    }
}
