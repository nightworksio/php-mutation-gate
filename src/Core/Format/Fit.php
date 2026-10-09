<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function array_slice;
use function count;
use function implode;
use function mb_scrub;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function sprintf;
use function str_replace;
use function trim;

/**
 * Text cut to a chat's limit by construction: whole lines kept while they
 * fit, then how many were left out, so no message a chat refuses is sent.
 * Text from outside, such as what a service answered or the name a
 * project gave a test, is made one plain line first, so it can start no
 * command in a CI's log. A long list of names shows its first few and how
 * many more it holds.
 */
final readonly class Fit
{
    /** How many items a shortened list shows: names, or the reasons a warning is told apart by. */
    public const int SHOWN = 3;

    /** Two texts on one line, the second after the first and a space. */
    public const string JOINED = '%s %s';

    /** Any run of white space, which a plain line holds as one space. */
    public const string SPACE = '/\s+/u';

    private const string MORE = 'And %d more.';

    /** The names a list shows, then how many more it holds. */
    private const string NAMED_MORE = '%s and %d more';

    /** What parts one shown name from the next. */
    private const string COMMA = ', ';

    /** What is added to a text that is cut, as `…`. */
    private const string CUT = '…';

    /**
     * A control or format character, or a line or paragraph separator: an
     * escape, a bell, a carriage return, a bidirectional override, a
     * zero-width space and the like.
     */
    private const string CONTROL = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    /** What ends a single-line comment in PHP besides the line, written so it does not. */
    private const string CLOSE_TAG = '?>';

    private const string OPEN_CLOSE_TAG = '? >';

    /**
     * As many of these lines, one to a line, as fit in this many characters with a line saying how many are left out.
     *
     * @param list<string> $lines
     */
    public static function lines(array $lines, int $limit): string
    {
        $all = implode("\n", $lines);

        if (mb_strlen($all) <= $limit) {
            return $all;
        }

        $kept = count($lines);

        do {
            --$kept;
            $fitted = implode("\n", [...array_slice($lines, 0, $kept), self::more(count($lines) - $kept)]);
        } while ($kept > 0 && mb_strlen($fitted) > $limit);

        return $fitted;
    }

    /** The line that says how many were left out. */
    public static function more(int $left): string
    {
        return sprintf(self::MORE, $left);
    }

    /**
     * Text from outside as one plain line: invalid UTF-8 replaced, every run
     * of white space a single space, and every control and format character
     * dropped, so an answer can start no workflow command, colour no
     * terminal and reorder no text it is shown in.
     */
    public static function plain(string $text): string
    {
        return trim(self::verbatim(preg_replace(self::SPACE, ' ', mb_scrub($text, 'UTF-8')) ?? ''));
    }

    /**
     * One line of text from outside as it is written, its spaces kept:
     * invalid UTF-8 replaced, and every control and format character, a tab
     * and a carriage return among them, and every line and paragraph
     * separator dropped.
     */
    public static function verbatim(string $line): string
    {
        return preg_replace(self::CONTROL, '', mb_scrub($line, 'UTF-8')) ?? '';
    }

    /**
     * One line of text from outside, written so it stays inside a
     * single-line comment of PHP, YAML or NEON: as `verbatim()` writes it,
     * so no line ends early, with no close tag, which ends PHP's mode.
     */
    public static function commentLine(string $line): string
    {
        return str_replace(self::CLOSE_TAG, self::OPEN_CLOSE_TAG, self::verbatim($line));
    }

    /**
     * The first few of these names, parted by commas, then how many more
     * there are: `a, b, c and 5 more`.
     *
     * @param list<string> $names
     */
    public static function named(array $names): string
    {
        $named = implode(self::COMMA, array_slice($names, 0, self::SHOWN));
        $more = count($names) - self::SHOWN;

        return $more > 0 ? sprintf(self::NAMED_MORE, $named, $more) : $named;
    }

    /** One line cut to this many characters, ending in `…` where it was cut. */
    public static function line(string $line, int $limit): string
    {
        return mb_strlen($line) <= $limit ? $line : sprintf('%s%s', mb_substr($line, 0, $limit - 1), self::CUT);
    }
}
