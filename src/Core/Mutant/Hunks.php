<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_find_key;
use function array_slice;
use function explode;
use function is_int;
use function str_starts_with;

/** The lines of a unified diff's hunks, without the header before the first. */
final readonly class Hunks
{
    /** The line a unified diff's first hunk starts with; what comes before it is the header. */
    private const string HUNK = '@@';

    /** @return list<string> the lines after the first hunk's own, or every line of a diff with no hunk */
    public static function linesOf(string $diff): array
    {
        $lines = explode("\n", $diff);
        $hunk = array_find_key($lines, static fn(string $line): bool => str_starts_with($line, self::HUNK));

        return is_int($hunk) ? array_slice($lines, $hunk + 1) : $lines;
    }
}
