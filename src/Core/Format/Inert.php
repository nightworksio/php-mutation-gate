<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function implode;
use function mb_scrub;
use function preg_replace;
use function preg_split;
use function sprintf;

/**
 * Text for a log a CI runner reads commands from, which starts none. GitHub
 * Actions reads a line whose text, once trimmed, starts with `::` as a
 * workflow command, and `##[` anywhere in a line as one in its older form;
 * Azure Pipelines reads `##vso[` anywhere in a line as a logging command,
 * and `##[` as a formatting command. Both end a line at `\r\n`, `\r` and
 * `\n`, and so does this. Such a line
 * keeps its words: a `\` goes before the `::`, after any colour, and a space
 * before the `[`, in any case; TeamCity's `##teamcity[` is made inert the
 * same way. Invalid UTF-8 is replaced first, as a runner reads it, and every
 * control and format character but the escape a colour starts with, a tab
 * and the ends of lines is dropped: .NET trims a vertical tab and a form
 * feed, and ICU, which the runners' string search uses, skips a zero-width
 * character, so none hides a command from this. Text from outside,
 * such as what an analyser, a test or a process said, can then reach the
 * log only as text. The gate's own commands, such as the Azure plan's
 * output variable, are written past it.
 */
final readonly class Inert
{
    /**
     * Every end of a line a runner reads, which .NET's `ReadLine` ends a line
     * at: `\r\n`, `\r` and `\n`. Each is kept, and holds nothing to make inert.
     */
    public const string LINE_END = '/(\r\n|\r|\n)/';
    /**
     * A line whose first text is `::`, after any white space the runner trims and any colour the console starts it
     * with; `\s` is Unicode's under `/u`.
     */
    private const string COMMAND = '/^((?:\s|\e\[[0-9;]*m)*)::/u';

    /** A command that starts anywhere in a line, in any case: GitHub's older form, Azure's two, and TeamCity's. */
    private const string ANYWHERE = '/##(vso|teamcity)?\[/i';

    /** A control or format character but the escape a colour starts with, a tab and the ends of lines. */
    private const string UNSHOWN = '/[^\P{Cc}\e\t\r\n]|\p{Cf}/u';

    public static function text(string $text): string
    {
        $scrubbed = preg_replace(self::UNSHOWN, '', mb_scrub($text, 'UTF-8')) ?? '';
        $parts = preg_split(self::LINE_END, $scrubbed, -1, PREG_SPLIT_DELIM_CAPTURE);
        $inert = [];

        foreach ($parts === false ? [$scrubbed] : $parts as $part) {
            $inert[] = self::line($part);
        }

        return implode('', $inert);
    }

    /**
     * Text that continues a line already holding this text, made inert so the whole line is: where the two would
     * start a command together that neither starts alone, it goes on a line of its own.
     */
    public static function continuing(string $line, string $text): string
    {
        $inert = self::text($text);

        $together = self::text(implode('', [$line, $text]));

        return $together === implode('', [self::text($line), $inert]) ? $inert : sprintf("\n%s", $inert);
    }

    private static function line(string $line): string
    {
        $anywhere = preg_replace(self::ANYWHERE, '##$1 [', $line) ?? '';

        return preg_replace(self::COMMAND, '$1\\\\::', $anywhere) ?? $anywhere;
    }
}
