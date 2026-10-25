<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use function count;
use function min;

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
        $apart = self::apart($old, $new);
        $lines = [];
        [$at, $to] = [0, 0];

        while ($at < count($old) && $to < count($new)) {
            [$mark, $at, $to] = match (true) {
                $old[$at] === $new[$to] => [self::SAME, $at + 1, $to + 1],
                $apart[$at + 1][$to] + 1 === $apart[$at][$to] => [self::GONE, $at + 1, $to],
                default => [self::ADDED, $at, $to + 1],
            };
            $lines[] = [$mark, $mark === self::ADDED ? $new[$to - 1] : $old[$at - 1]];
        }

        return [...$lines, ...self::marked(self::GONE, $old, $at), ...self::marked(self::ADDED, $new, $to)];
    }

    /**
     * How many lines the rest of each text, from each pair of places, holds that the other's rest does not
     * share: the fewest lines a diff of the rests marks gone or added.
     *
     * @param  list<string>                $old
     * @param  list<string>                $new
     * @return array<int, array<int, int>>
     */
    private static function apart(array $old, array $new): array
    {
        $apart = [];
        $below = [];

        for ($at = count($old); $at >= 0; $at--) {
            $below = self::row($old, $new, $at, $below);
            $apart[$at] = $below;
        }

        return $apart;
    }

    /**
     * The lines apart from each place in the new text, from one place in the old, by those from the next.
     *
     * @param  list<string>     $old
     * @param  list<string>     $new
     * @param  array<int, int>  $below the row of the next place in the old text; none past its end
     * @return array<int, int>
     */
    private static function row(array $old, array $new, int $at, array $below): array
    {
        $row = [count($new) => count($old) - $at];

        for ($to = count($new) - 1; $to >= 0; $to--) {
            $row[$to] = match (true) {
                $at === count($old) => $row[$to + 1] + 1,
                $old[$at] === $new[$to] => $below[$to + 1],
                default => 1 + min($below[$to], $row[$to + 1]),
            };
        }

        return $row;
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
