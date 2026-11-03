<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;
use function array_pop;
use function array_reverse;
use function array_splice;
use function explode;
use function implode;
use function mb_substr;
use function preg_match;
use function str_starts_with;

/** A file as it was, from the file as it is and a `--unified=0` patch from it back to what it was. */
final readonly class ReversedPatch
{
    /** What begins a hunk: where its lines stand in the file as it is, and how many there are. */
    private const string HUNK = '/^@@ -(\d+)(?:,(\d+))? \+\d+(?:,\d+)? @@/';

    /** What begins a line the patch puts back. */
    private const string ADDED = '+';

    public static function applied(string $now, string $patch): string
    {
        $lines = explode("\n", $now);

        foreach (array_reverse(self::hunks($patch)) as [$start, $count, $was]) {
            array_splice($lines, $count === 0 ? $start : $start - 1, $count, $was);
        }

        return implode("\n", $lines);
    }

    /** @return list<array{int, int, list<string>}> each hunk's first line as it is, its count, and the lines it puts back */
    private static function hunks(string $patch): array
    {
        $hunks = [];

        foreach (explode("\n", $patch) as $line) {
            $hunks = preg_match(self::HUNK, $line, $at) === 1
                ? [...$hunks, [(int) $at[1], array_key_exists(2, $at) ? (int) $at[2] : 1, []]]
                : self::adding($hunks, $line);
        }

        return $hunks;
    }

    /**
     * @param  list<array{int, int, list<string>}> $hunks
     * @return list<array{int, int, list<string>}>
     */
    private static function adding(array $hunks, string $line): array
    {
        $last = array_pop($hunks);

        if ($last === null) {
            return [];
        }

        [$start, $count, $was] = $last;

        return [...$hunks, [$start, $count, str_starts_with($line, self::ADDED) ? [...$was, mb_substr($line, 1)] : $was]];
    }
}
