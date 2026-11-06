<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Fix;

use function sprintf;
use function str_ends_with;

/**
 * A text file's lines, as `doctor --fix` and `init` add to them.
 *
 * @internal
 */
final readonly class Lines
{
    private const string NEWLINE = "\n";

    /** The text with a line added at its end, after the newline its last line lacks, where it lacks one. */
    public static function appended(string $text, string $line): string
    {
        $joint = $text === '' || str_ends_with($text, self::NEWLINE) ? '' : self::NEWLINE;

        return sprintf('%s%s%s%s', $text, $joint, $line, self::NEWLINE);
    }
}
