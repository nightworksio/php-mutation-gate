<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function implode;
use function mb_scrub;
use function preg_replace;
use function preg_split;

/**
 * Text for a log a CI runner reads commands from, which starts none. GitHub
 * Actions reads a line whose text, once trimmed, starts with `::` as a
 * workflow command, and `##[` anywhere in a line as one in its older form;
 * Azure Pipelines reads `##vso[` anywhere in a line as a logging command,
 * and `##[` as a formatting command. Both end a line at `\r\n`, `\r` and
 * `\n`, and so does this. Such a line
 * keeps its words: a `\` goes before the `::`, and a space before the `[`;
 * invalid UTF-8 is replaced first, as a runner reads it. Text from outside,
 * such as what an analyser, a test or a process said, can then reach the
 * log only as text. The gate's own commands, such as the Azure plan's
 * output variable, are written past it.
 */
final readonly class Inert
{
    /** A line whose first text is `::`, after any white space the runner trims; `\s` is Unicode's under `/u`. */
    private const string COMMAND = '/^(\s*)::/u';

    /** A command that starts anywhere in a line: GitHub's older form, and Azure's two. */
    private const string ANYWHERE = '/##(vso)?\[/';

    /** Every end of a line a runner reads, which .NET's `ReadLine` ends a line at: `\r\n`, `\r` and `\n`. */
    private const string LINE_END = '/(\r\n|\r|\n)/';

    public static function text(string $text): string
    {
        $scrubbed = mb_scrub($text, 'UTF-8');
        $parts = preg_split(self::LINE_END, $scrubbed, -1, PREG_SPLIT_DELIM_CAPTURE);
        $inert = [];

        foreach ($parts === false ? [$scrubbed] : $parts as $at => $part) {
            $inert[] = $at % 2 === 1 ? $part : self::line($part);
        }

        return implode('', $inert);
    }

    private static function line(string $line): string
    {
        $anywhere = preg_replace(self::ANYWHERE, '##$1 [', $line) ?? '';

        return preg_replace(self::COMMAND, '$1\\\\::', $anywhere) ?? $anywhere;
    }
}
