<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Fix;

use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\Runner\PcovReach;

use function preg_match;
use function preg_quote;
use function sprintf;

/**
 * A php.ini as `doctor --fix` changes it (ADR-0083): a setting set where a
 * line already sets it, the last such line, or added at the end, and an
 * extension loaded where no line loads it. A commented line is left alone.
 *
 * @internal
 */
final readonly class IniText
{
    private const string NEWLINE = "\n";

    private const string SETTING = '/^\s*%s\s*=/u';

    private const string EXTENSION = '/^\s*extension\s*=\s*"?(?:php_)?%s(?:\.so|\.dll)?"?\s*(?:;.*)?$/u';

    private const string LOAD = 'extension';

    /** The text with a setting at this value. */
    public static function setting(string $text, string $name, string $value): string
    {
        $lines = explode(self::NEWLINE, $text);
        $setting = sprintf(self::SETTING, preg_quote($name, '/'));
        $last = false;

        foreach ($lines as $at => $line) {
            $last = preg_match($setting, $line) === 1 ? $at : $last;
        }

        if ($last === false) {
            return Lines::appended($text, sprintf(PcovReach::SETTING, $name, $value));
        }

        $lines[$last] = sprintf(PcovReach::SETTING, $name, $value);

        return implode(self::NEWLINE, $lines);
    }

    /** The text with an extension loaded, as it was where a line loads it already. */
    public static function extension(string $text, string $extension): string
    {
        $loading = sprintf(self::EXTENSION, preg_quote($extension, '/'));

        foreach (explode(self::NEWLINE, $text) as $line) {
            if (preg_match($loading, $line) === 1) {
                return $text;
            }
        }

        return Lines::appended($text, sprintf(PcovReach::SETTING, self::LOAD, $extension));
    }
}
