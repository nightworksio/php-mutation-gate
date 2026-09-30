<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_find_key;
use function array_slice;
use function explode;
use function is_int;
use function sprintf;
use function str_starts_with;

/** The lines of a unified diff's hunks, without the header before the first, and one hunk made from changed lines. */
final readonly class Hunks
{
    /** The line a unified diff's first hunk starts with; what comes before it is the header. */
    private const string HUNK = '@@';

    /** The line a hunk the gate makes starts with: it holds no line numbers, which the mutant's location has. */
    private const string HEADER = '@@ @@';

    /** One hunk of these lines, each already marked with ` `, `-` or `+`. */
    public static function of(string $lines): string
    {
        return sprintf("%s\n%s", self::HEADER, $lines);
    }

    /** @return list<string> the lines after the first hunk's own, or every line of a diff with no hunk */
    public static function linesOf(string $diff): array
    {
        $lines = explode("\n", $diff);
        $hunk = array_find_key($lines, static fn(string $line): bool => str_starts_with($line, self::HUNK));

        return is_int($hunk) ? array_slice($lines, $hunk + 1) : $lines;
    }
}
