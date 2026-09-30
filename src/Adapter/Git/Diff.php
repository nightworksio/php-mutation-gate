<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_key_exists;
use function array_map;
use function array_merge;
use function array_pop;
use function count;
use function explode;
use function intval;
use function is_string;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;

use function preg_match;
use function preg_match_all;
use function range;
use function rtrim;
use function stripcslashes;

/** What git's diffs say: which paths changed and how, and which lines each gained on its new side. */
final readonly class Diff
{
    /**
     * One entry of `--name-status -z`: its kind, the path it came from ended
     * by its NUL (empty but for a rename, whose score is 0 to 100), and its path.
     */
    private const string ENTRY = <<<'REGEX'
        ~(?<kind>[A-Z])\d*\0
        (?<from>(?:(?<=R\d\0|R\d\d\0|R\d\d\d\0)[^\0]*\0)?)
        (?<path>[^\0]*)\0~x
        REGEX;

    /** Where each file's part of a patch starts, past the first. */
    private const string PART = "\ndiff --git ";

    /**
     * How a part of `--unified=0` names its file's new side: in double quotes
     * where git had to escape the name, and with a tab after it where the name
     * holds a space. It comes before any hunk, so the first line like it is it.
     */
    private const string NEW_SIDE = '~^\+\+\+ "?b/(?<path>.*?)"?\t?$~m';

    /** Each hunk header of a part, with where its new side starts and how many lines it spans. */
    private const string HUNK = '~^@@ -\d+(?:,\d+)? \+(?<start>\d+)(?:,(?<count>\d+))? @@~m';

    /**
     * Every changed path, from `git diff --name-status -z`, with the lines
     * each gained.
     *
     * @param array<string, Lines> $lines the lines each path gained, by its path
     */
    public static function changes(string $status, array $lines): Changes
    {
        preg_match_all(self::ENTRY, $status, $entries, PREG_SET_ORDER);
        $changes = [];

        foreach ($entries as $entry) {
            $from = rtrim($entry['from'], "\0");
            $changes[] = self::change($entry['kind'], $from, $entry['path'], $lines);
        }

        return Changes::of(...$changes);
    }

    /**
     * The lines each file gained on its new side, from `git diff --unified=0`.
     *
     * @return array<string, Lines>
     */
    public static function lines(string $patch): array
    {
        $lines = [];

        foreach (explode(self::PART, $patch) as $part) {
            if (preg_match(self::NEW_SIDE, $part, $side) === 1) {
                $lines[stripcslashes($side['path'])] = self::gainedIn($part);
            }
        }

        return $lines;
    }

    /** Every line of a text, as the lines a new file gains. */
    public static function whole(string $text): Lines
    {
        $pieces = explode("\n", $text);
        $last = array_pop($pieces);

        return self::linesAt(self::spanning(1, count($pieces) + ($last === '' ? 0 : 1)));
    }

    /** @param array<string, Lines> $lines */
    private static function change(string $kind, string $from, string $path, array $lines): Change
    {
        return match ($kind) {
            'R' => Change::renamed(Path::of($from), Path::of($path), self::linesOf($lines, $path)),
            'A' => Change::added(Path::of($path), self::linesOf($lines, $path)),
            'D' => Change::deleted(Path::of($path)),
            default => Change::modified(Path::of($path), self::linesOf($lines, $path)),
        };
    }

    /** @param array<string, Lines> $lines */
    private static function linesOf(array $lines, string $path): Lines
    {
        return array_key_exists($path, $lines) ? $lines[$path] : Lines::none();
    }

    /** The lines the hunks of one file's part of a patch gained. */
    private static function gainedIn(string $part): Lines
    {
        preg_match_all(self::HUNK, $part, $hunks, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $spans = [];

        foreach ($hunks as $hunk) {
            $count = is_string($hunk['count']) ? intval($hunk['count']) : 1;
            $spans[] = self::spanning(intval($hunk['start']), $count);
        }

        return self::linesAt(array_merge(...$spans));
    }

    /**
     * The numbers of the lines a hunk spans from its start.
     *
     * @return list<int>
     */
    private static function spanning(int $start, int $count): array
    {
        return $count < 1 ? [] : range($start, $start + $count - 1);
    }

    /** @param list<int> $numbers */
    private static function linesAt(array $numbers): Lines
    {
        return Lines::of(...array_map(Line::of(...), $numbers));
    }
}
