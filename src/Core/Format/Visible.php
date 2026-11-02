<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function mb_ord;
use function mb_scrub;
use function preg_replace_callback;
use function sprintf;

/**
 * Text the code under test can shape, made visible as it is: every control
 * character, C1's among them, every line and paragraph separator, and every
 * format character, a bidirectional override among them, written as a
 * visible escape, so none moves a terminal's cursor, sets its title, links
 * text or reorders what a reader sees. As one line, a backslash, a line
 * break, a carriage return and a tab are escaped too, so no value ends its
 * line or starts one a reader would take for the gate's; as text, line
 * breaks and tabs are kept.
 *
 * @internal
 */
final readonly class Visible
{
    /** What one line writes as an escape. */
    private const string IN_A_LINE = '/[\\\\\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    /** What text writes as an escape: the same but a backslash, a line break and a tab. */
    private const string IN_TEXT = '/[^\P{Cc}\n\t]|[\p{Cf}\p{Zl}\p{Zp}]/u';

    /** How a character with no short escape is written. */
    private const string CODE_POINT = '\u{%04x}';

    /** A value as one line nothing in it can end or forge. */
    public static function line(string $value): string
    {
        return self::escaping(self::IN_A_LINE, $value);
    }

    /** Text with its line breaks and tabs, and nothing else in it able to act on what shows it. */
    public static function text(string $text): string
    {
        return self::escaping(self::IN_TEXT, $text);
    }

    private static function escaping(string $unsafe, string $text): string
    {
        return preg_replace_callback(
            $unsafe,
            static fn(array $found): string => self::escaped($found[0]),
            mb_scrub($text, 'UTF-8'),
        ) ?? throw FormatFault::pcre();
    }

    private static function escaped(string $character): string
    {
        $short = ShortEscape::tryFrom($character);

        return $short instanceof ShortEscape
            ? $short->written()
            : sprintf(self::CODE_POINT, mb_ord($character, 'UTF-8'));
    }
}
