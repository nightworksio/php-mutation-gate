<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use function array_fill;
use function count;
use function max;

/** The lines two texts share, and those each holds alone, in order: the longest common subsequence of their lines. */
final readonly class LineDiff
{
    public const string SAME = ' ';

    public const string GONE = '-';

    public const string ADDED = '+';

    /**
     * Each line, marked as both texts hold it, as only the old one does, or as only the new one does.
     *
     * @param  list<string>               $old
     * @param  list<string>               $new
     * @return list<array{string, string}>
     */
    public static function of(array $old, array $new): array
    {
        $shared = self::shared($old, $new);
        $lines = [];
        [$at, $to] = [0, 0];

        while ($at < count($old) && $to < count($new)) {
            [$mark, $at, $to] = match (true) {
                $old[$at] === $new[$to] => [self::SAME, $at + 1, $to + 1],
                $shared[$at + 1][$to] >= $shared[$at][$to + 1] => [self::GONE, $at + 1, $to],
                default => [self::ADDED, $at, $to + 1],
            };
            $lines[] = [$mark, $mark === self::ADDED ? $new[$to - 1] : $old[$at - 1]];
        }

        return [...$lines, ...self::marked(self::GONE, $old, $at), ...self::marked(self::ADDED, $new, $to)];
    }

    /**
     * How many lines the rest of each text, from each pair of places, shares.
     *
     * @param  list<string>          $old
     * @param  list<string>          $new
     * @return list<array<int, int>>
     */
    private static function shared(array $old, array $new): array
    {
        $shared = array_fill(0, count($old) + 1, array_fill(0, count($new) + 1, 0));

        for ($at = count($old) - 1; $at >= 0; $at--) {
            for ($to = count($new) - 1; $to >= 0; $to--) {
                $shared[$at][$to] = $old[$at] === $new[$to]
                    ? $shared[$at + 1][$to + 1] + 1
                    : max($shared[$at + 1][$to], $shared[$at][$to + 1]);
            }
        }

        return $shared;
    }

    /**
     * @param  list<string>               $lines
     * @return list<array{string, string}>
     */
    private static function marked(string $mark, array $lines, int $from): array
    {
        $marked = [];
        $counter = count($lines);

        for ($at = $from; $at < $counter; $at++) {
            $marked[] = [$mark, $lines[$at]];
        }

        return $marked;
    }
}
