<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_map;
use function array_merge;
use function array_pop;
use function count;
use function explode;
use function intval;
use function is_string;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

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
     * @param ByPath<Lines> $lines the lines each path gained
     */
    public static function changes(string $status, ByPath $lines): Changes
    {
        preg_match_all(self::ENTRY, $status, $entries, PREG_SET_ORDER);
        $changes = [];

        foreach ($entries as $entry) {
            $from = rtrim($entry['from'], "\0");
            $changes[] = self::change($entry['kind'], $from, Path::of($entry['path']), $lines);
        }

        return Changes::of(...$changes);
    }

    /**
     * The lines each file gained on its new side, from `git diff --unified=0`.
     *
     * @return ByPath<Lines>
     */
    public static function lines(string $patch): ByPath
    {
        $lines = [];
        $paths = [];

        foreach (explode(self::PART, $patch) as $part) {
            if (preg_match(self::NEW_SIDE, $part, $side) === 1) {
                $path = Path::of(stripcslashes($side['path']));
                $lines[$path->value()] = self::gainedIn($part);
                $paths[] = $path;
            }
        }

        return ByPath::mapping(Paths::of(...$paths), static fn(Path $path): Lines => $lines[$path->value()]);
    }

    /** Every line of a text, as the lines a new file gains. */
    public static function whole(string $text): Lines
    {
        $pieces = explode("\n", $text);
        $last = array_pop($pieces);

        return self::linesAt(self::spanning(1, count($pieces) + ($last === '' ? 0 : 1)));
    }

    /** @param ByPath<Lines> $lines */
    private static function change(string $kind, string $from, Path $path, ByPath $lines): Change
    {
        $gained = $lines->at($path, Lines::none());

        return match ($kind) {
            'R' => Change::renamed(Path::of($from), $path, $gained),
            'A' => Change::added($path, $gained),
            'D' => Change::deleted($path),
            default => Change::modified($path, $gained),
        };
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
