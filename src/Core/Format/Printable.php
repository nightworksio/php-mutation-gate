<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function mb_scrub;
use function preg_replace;

/**
 * Text from outside, such as a test's name, a diff or what a process said, as a terminal or a CI's log shows it:
 * invalid UTF-8 replaced, and every control and format character but a tab and the ends of lines dropped, so it
 * starts no escape sequence there and reorders no text around it. The console takes everything it is given to write
 * through it before it adds its own colour (InertOutput), and a workflow command its message and properties
 * (WorkflowCommand).
 */
final readonly class Printable
{
    /** The encoding of text from outside, as it is read and cut. */
    public const string UTF8 = 'UTF-8';
    /**
     * A control or format character but a tab and the ends of lines: what starts an escape sequence a terminal or a
     * CI's log reads, such as ANSI's erase and conceal, GitLab's sections and Buildkite's inline images, and a
     * bidirectional override.
     */
    private const string UNPRINTABLE = '/[^\P{Cc}\t\r\n]|\p{Cf}/u';

    public static function text(string $text): string
    {
        return preg_replace(self::UNPRINTABLE, '', mb_scrub($text, self::UTF8)) ?? '';
    }
}
