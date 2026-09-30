<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function explode;
use function implode;
use function mb_scrub;
use function preg_replace;

/**
 * Text for a log a CI runner reads commands from, which starts none. GitHub
 * Actions reads a line whose text, once trimmed, starts with `::` as a
 * workflow command, and `##[` anywhere in a line as one in its older form;
 * Azure Pipelines reads `##vso[` and `##[` anywhere in a line. Such a line
 * keeps its words: a `\` goes before the `::`, and a space before the `[`;
 * invalid UTF-8 is replaced first, as a runner reads it. Text from outside,
 * such as what an analyser, a test or a process said, can then reach the
 * log only as text. The gate's own commands, such as the Azure plan's
 * output variable, are written past it.
 */
final readonly class Inert
{
    /** A line whose first text is `::`, after any white space the runner trims, Unicode's included. */
    private const string COMMAND = '/^([\s\x{85}\p{Z}]*)::/u';

    /** A command that starts anywhere in a line: GitHub's older form, and Azure's two. */
    private const string ANYWHERE = '/##(vso)?\[/';

    public static function text(string $text): string
    {
        $lines = [];

        foreach (explode("\n", $text) as $line) {
            $anywhere = preg_replace(self::ANYWHERE, '##$1 [', mb_scrub($line, 'UTF-8')) ?? '';
            $lines[] = preg_replace(self::COMMAND, '$1\\\\::', $anywhere) ?? $anywhere;
        }

        return implode("\n", $lines);
    }
}
