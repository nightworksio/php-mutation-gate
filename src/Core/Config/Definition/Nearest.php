<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_first;
use function intdiv;
use function is_string;
use function levenshtein;
use function max;
use function mb_strlen;
use function mb_strtolower;
use function usort;

/**
 * The known key a misspelt one most likely meant: `newcode` is `newCode`. A
 * key is close enough when it is one edit away, or a third of its length
 * for a longer one; a key that close to nothing has no suggestion.
 */
final readonly class Nearest
{
    private const int FEWEST_EDITS = 1;

    private const int LETTERS_PER_EDIT = 3;

    /**
     * The nearest known key, the first of those equally near, or '' when none is close.
     *
     * @param list<string> $known
     */
    public static function to(string $key, array $known): string
    {
        $ranked = $known;
        usort(
            $ranked,
            static fn(string $one, string $other): int => self::edits($key, $one) <=> self::edits($key, $other),
        );
        $nearest = array_first($ranked);
        $allowed = max(self::FEWEST_EDITS, intdiv(mb_strlen($key), self::LETTERS_PER_EDIT));

        return is_string($nearest) && self::edits($key, $nearest) <= $allowed ? $nearest : '';
    }

    private static function edits(string $key, string $known): int
    {
        return levenshtein(mb_strtolower($key), mb_strtolower($known));
    }
}
