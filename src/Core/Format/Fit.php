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
use function trim;

/**
 * Text cut to a chat's limit by construction: whole lines kept while they
 * fit, then how many were left out, so no message a chat refuses is sent.
 * Text from outside, such as what a service answered or the name a
 * project gave a test, is made one plain line first, so it can start no
 * command in a CI's log.
 */
final readonly class Fit
{
    private const string MORE = 'And %d more.';

    /** What is added to a text that is cut, as `…`. */
    private const string CUT = '…';

    /** Any run of white space, which a plain line holds as one space. */
    private const string SPACE = '/\s+/u';

    /** A control character: an escape, a bell, a carriage return and the like. */
    private const string CONTROL = '/\p{Cc}/u';

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
     * of white space a single space, and every control character dropped,
     * so an answer can start no workflow command and colour no terminal.
     */
    public static function plain(string $text): string
    {
        $spaced = preg_replace(self::SPACE, ' ', mb_scrub($text, 'UTF-8')) ?? '';

        return trim(preg_replace(self::CONTROL, '', $spaced) ?? '');
    }

    /** One line cut to this many characters, ending in `…` where it was cut. */
    public static function line(string $line, int $limit): string
    {
        return mb_strlen($line) <= $limit ? $line : sprintf('%s%s', mb_substr($line, 0, $limit - 1), self::CUT);
    }
}
