<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function abs;
use function array_slice;
use function count;
use function explode;
use function implode;
use function mb_substr;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;

use function sprintf;
use function str_starts_with;
use function usort;

/**
 * A mutant's unified diff, put back onto the file it was made from to give
 * the mutant's own text (ADR-0020, decision 9). A runner's diff holds no
 * line numbers, so each hunk is placed where its context and removed lines
 * stand in the file: the first hunk at the place nearest the mutant's line,
 * each later one at the first place after the one before. A diff that does
 * not apply gives no mutant, so the mutant is left unchecked, never killed.
 */
final readonly class DiffPatch
{
    private const string HUNK = '@@';

    private const string CONTEXT = ' ';

    private const string REMOVED = '-';

    private const string ADDED = '+';

    /** A line a diff adds after a hunk to say its file does not end in a line break. */
    private const string NO_NEWLINE = '\\';

    private const string DOES_NOT_APPLY = 'Its diff does not apply to %s as it is now.';

    /** @param list<array{list<string>, list<string>, int}> $hunks each hunk's lines before and after, and its lead */
    private function __construct(private array $hunks)
    {
    }

    public static function of(Mutation $mutation): self
    {
        $hunks = [];
        $current = [];

        foreach (explode("\n", $mutation->diff()) as $line) {
            [$hunks, $current] = str_starts_with($line, self::HUNK)
                ? [self::closed($hunks, $current), [[], [], 0, false]]
                : [$hunks, self::read($current, $line)];
        }

        return new self(self::closed($hunks, $current));
    }

    /** The mutant's text: the diff put onto this original, the mutant's, nearest where the mutant starts. */
    public function onto(Contents $original, Location $at): Contents|CannotJudge
    {
        $lines = explode("\n", $original->text());
        $patched = [];
        $cursor = 0;
        $want = $at->start()->number() - 1;

        foreach ($this->hunks as [$before, $after, $lead]) {
            $places = $this->places($lines, $before, $cursor, $want - $lead);

            if ($places === []) {
                return CannotJudge::because(sprintf(self::DOES_NOT_APPLY, $at->file()->value()));
            }

            $patched = [...$patched, ...array_slice($lines, $cursor, $places[0] - $cursor), ...$after];
            $cursor = $places[0] + count($before);
            $want = $cursor;
        }

        return $this->hunks === []
            ? CannotJudge::because(sprintf(self::DOES_NOT_APPLY, $at->file()->value()))
            : Contents::of(implode("\n", [...$patched, ...array_slice($lines, $cursor)]));
    }

    /**
     * The hunks so far, with the one being read closed: the empty lines the
     * diff's own last line break leaves at its end dropped.
     *
     * @param  list<array{list<string>, list<string>, int}>          $hunks
     * @param  array{}|array{list<string>, list<string>, int, bool}  $current
     * @return list<array{list<string>, list<string>, int}>
     */
    private static function closed(array $hunks, array $current): array
    {
        if ($current === []) {
            return $hunks;
        }

        [$before, $after, $lead] = $current;

        while (self::endsBlank($before) && self::endsBlank($after)) {
            $before = array_slice($before, 0, -1);
            $after = array_slice($after, 0, -1);
        }

        return [...$hunks, [$before, $after, $lead]];
    }

    /**
     * The hunk being read, with one more line of the diff: context in both
     * sides, a removed line before, an added line after. A line before the
     * first hunk is the diff's header, and a note on a missing line break
     * says nothing of the text.
     *
     * @param  array{}|array{list<string>, list<string>, int, bool} $current
     * @return array{}|array{list<string>, list<string>, int, bool}
     */
    private static function read(array $current, string $line): array
    {
        if ($current === [] || str_starts_with($line, self::NO_NEWLINE)) {
            return $current;
        }

        [$before, $after, $lead, $changed] = $current;
        $mark = $line === '' ? self::CONTEXT : mb_substr($line, 0, 1);
        $text = mb_substr($line, 1);

        return match ($mark) {
            self::REMOVED => [[...$before, $text], $after, $lead, true],
            self::ADDED => [$before, [...$after, $text], $lead, true],
            default => [[...$before, $text], [...$after, $text], $changed ? $lead : $lead + 1, $changed],
        };
    }

    /** @param list<string> $lines */
    private static function endsBlank(array $lines): bool
    {
        return $lines !== [] && $lines[count($lines) - 1] === '';
    }

    /**
     * Every place these lines stand in the file at or after the cursor, the
     * one nearest the place wanted first.
     *
     * @param  list<string> $lines
     * @param  list<string> $before
     * @return list<int>
     */
    private function places(array $lines, array $before, int $cursor, int $want): array
    {
        $places = [];
        $length = count($before);
        $last = count($lines) - $length;

        for ($at = $cursor; $at <= $last; $at++) {
            if (array_slice($lines, $at, $length) === $before) {
                $places[] = $at;
            }
        }

        usort($places, static fn(int $a, int $b): int => abs($a - $want) <=> abs($b - $want));

        return $places;
    }
}
