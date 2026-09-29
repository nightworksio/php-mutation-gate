<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_key_exists;
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

    /** The header of a hunk of `--unified=0`, with where its new side starts and how many lines it spans. */
    private const string HUNK = '~^@@ -\d+(?:,\d+)? \+(?<start>\d+)(?:,(?<count>\d+))? @@~';

    /**
     * How `--unified=0` names the new side of a file: in double quotes where
     * it had to escape the name, and with a tab after it where the name holds
     * a space.
     */
    private const string NEW_SIDE = '~^\+\+\+ (?<quote>"?)b/(?<path>.*?)"?\t?$~';

    /**
     * Every changed path, from `git diff --name-status -z`, with the lines
     * each gained.
     *
     * @param array<string, Lines> $lines the lines each path gained, by its path
     */
    public static function changes(string $status, array $lines): Changes
    {
        preg_match_all(self::ENTRY, $status, $entries, PREG_SET_ORDER);
        $changes = Changes::none();

        foreach ($entries as $entry) {
            $from = rtrim($entry['from'], "\0");
            $changes = $changes->with(self::change($entry['kind'], $from, $entry['path'], $lines));
        }

        return $changes;
    }

    /**
     * The lines each file gained on its new side, from `git diff --unified=0`.
     *
     * @return array<string, Lines>
     */
    public static function lines(string $patch): array
    {
        $lines = [];
        $file = '';

        foreach (explode("\n", $patch) as $line) {
            $file = self::newSideIn($line, $file);

            if (preg_match(self::HUNK, $line, $hunk, PREG_UNMATCHED_AS_NULL) === 1) {
                $lines[$file] = self::spanning(
                    self::linesOf($lines, $file),
                    intval($hunk['start']),
                    is_string($hunk['count']) ? intval($hunk['count']) : 1,
                );
            }
        }

        return $lines;
    }

    /** Every line of a text, as the lines a new file gains. */
    public static function whole(string $text): Lines
    {
        $pieces = explode("\n", $text);
        $last = array_pop($pieces);

        return self::spanning(Lines::none(), 1, count($pieces) + ($last === '' ? 0 : 1));
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

    /** The file a line of the patch starts, or the one before it where it starts none. */
    private static function newSideIn(string $line, string $file): string
    {
        if (preg_match(self::NEW_SIDE, $line, $side) !== 1) {
            return $file;
        }

        return $side['quote'] === '' ? $side['path'] : stripcslashes($side['path']);
    }

    /** These lines, and the ones a hunk spans from its start. */
    private static function spanning(Lines $lines, int $start, int $count): Lines
    {
        for ($line = $start; $line < $start + $count; ++$line) {
            $lines = $lines->with(Line::of($line));
        }

        return $lines;
    }
}
