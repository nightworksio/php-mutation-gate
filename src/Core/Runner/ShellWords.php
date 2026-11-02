<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_map;
use function escapeshellarg;
use function implode;

/**
 * Words joined into one line a POSIX shell splits back into the same words, each quoted whatever it holds.
 *
 * @internal
 */
final readonly class ShellWords
{
    /** @param list<string> $words */
    public static function line(array $words): string
    {
        return implode(' ', array_map(escapeshellarg(...), $words));
    }
}
