<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function implode;
use function preg_match;
use function sprintf;
use function str_replace;

/**
 * One CSV record as RFC 4180 writes it, ending in CRLF. A cell a spreadsheet
 * would read as a formula, one starting with `=`, `+`, `-`, `@`, a tab or a
 * carriage return, is written after a `'`, so a name the project chose never
 * runs in someone's spreadsheet.
 */
final readonly class Csv
{
    /** What a spreadsheet reads a cell that starts so as: a formula. */
    private const string FORMULA = '/^[=+\-@\t\r]/';

    /** What makes a cell need quotes. */
    private const string QUOTED = '/[",\r\n]/';

    public static function record(string ...$cells): string
    {
        $written = [];

        foreach ($cells as $cell) {
            $written[] = self::cell($cell);
        }

        return sprintf("%s\r\n", implode(',', $written));
    }

    private static function cell(string $cell): string
    {
        $inert = preg_match(self::FORMULA, $cell) === 1 ? sprintf("'%s", $cell) : $cell;

        return preg_match(self::QUOTED, $inert) === 1 ? sprintf('"%s"', str_replace('"', '""', $inert)) : $inert;
    }
}
