<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Format;

use function array_pop;
use function array_values;
use function implode;
use function sprintf;

/** Words, as a sentence lists them: `a`, `a and b`, `a, b and c`; or with `or` in place of `and`. */
final readonly class Series
{
    public static function and(string ...$words): string
    {
        return self::joined(array_values($words), 'and');
    }

    public static function or(string ...$words): string
    {
        return self::joined(array_values($words), 'or');
    }

    /** @param list<string> $words */
    private static function joined(array $words, string $last): string
    {
        $final = array_pop($words);

        return $words === [] ? (string) $final : sprintf('%s %s %s', implode(', ', $words), $last, $final);
    }
}
