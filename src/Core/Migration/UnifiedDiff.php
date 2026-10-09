<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use function array_filter;
use function array_keys;
use function array_slice;
use function count;
use function end;
use function explode;
use function implode;
use function max;
use function min;
use function sprintf;

/** Two versions of a file, as `diff -u` shows them: each changed line, with three lines around it. */
final readonly class UnifiedDiff
{
    /** How many unchanged lines `diff -u` shows on each side of a change. */
    private const int CONTEXT = 3;

    private const string DIFF = "--- a/%s\n+++ b/%s\n%s\n";

    private const string HUNK = '@@ -%d,%d +%d,%d @@';

    private const string NEWLINE = "\n";

    /** The diff of a file, nothing where both versions are the same. */
    public static function between(string $file, string $before, string $after): string
    {
        if ($before === $after) {
            return '';
        }

        $lines = LineDiff::of(self::lines($before), self::lines($after));
        $hunks = [];

        foreach (self::ranges($lines) as [$from, $to]) {
            $hunks[] = self::hunk($lines, $from, $to);
        }

        return sprintf(self::DIFF, $file, $file, implode(self::NEWLINE, $hunks));
    }

    /**
     * Where each hunk starts and ends among the lines: each change with its context, joined where they touch.
     *
     * @param  list<array{string, string}> $lines
     * @return list<array{int, int}>
     */
    private static function ranges(array $lines): array
    {
        $changed = array_keys(array_filter($lines, static fn(array $line): bool => $line[0] !== LineDiff::SAME));
        $ranges = [];

        foreach ($changed as $at) {
            $from = max(0, $at - self::CONTEXT);
            $to = min(count($lines) - 1, $at + self::CONTEXT);
            $last = count($ranges) - 1;
            $touches = $last >= 0 && $from <= $ranges[$last][1] + 1;
            $ranges = $touches
                ? [...array_slice($ranges, 0, $last), [$ranges[$last][0], $to]]
                : [...$ranges, [$from, $to]];
        }

        return $ranges;
    }

    /** @param list<array{string, string}> $lines */
    private static function hunk(array $lines, int $from, int $to): string
    {
        $before = array_slice($lines, 0, $from);
        $within = array_slice($lines, $from, $to - $from + 1);
        $shown = [];

        foreach ($within as [$mark, $line]) {
            $shown[] = sprintf('%s%s', $mark, $line);
        }

        return implode(self::NEWLINE, [
            sprintf(
                self::HUNK,
                self::counted($before, LineDiff::ADDED) + 1,
                self::counted($within, LineDiff::ADDED),
                self::counted($before, LineDiff::GONE) + 1,
                self::counted($within, LineDiff::GONE),
            ),
            ...$shown,
        ]);
    }

    /**
     * How many of these lines one side holds: every line but those only the other side holds.
     *
     * @param list<array{string, string}> $lines
     */
    private static function counted(array $lines, string $otherSide): int
    {
        return count(array_filter($lines, static fn(array $line): bool => $line[0] !== $otherSide));
    }

    /**
     * A text's lines, the newline that ends its last one ending no further, empty line.
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = explode(self::NEWLINE, $text);

        return end($lines) === '' ? array_slice($lines, 0, -1) : $lines;
    }
}
