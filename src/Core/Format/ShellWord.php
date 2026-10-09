<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function preg_match;
use function sprintf;
use function str_replace;

/** A word as a POSIX shell reads it: as it is where that is safe, and otherwise in single quotes. */
final readonly class ShellWord
{
    /** A word the shell reads as it is written, with nothing to quote. */
    private const string PLAIN = '#^[A-Za-z0-9_./-]+$#';

    private const string QUOTED = "'%s'";

    /** A single quote, as a single-quoted word spells it: closed, escaped and opened again. */
    private const string QUOTE = "'\\''";

    public static function of(string $word): string
    {
        return preg_match(self::PLAIN, $word) === 1
            ? $word
            : sprintf(self::QUOTED, str_replace("'", self::QUOTE, $word));
    }
}
