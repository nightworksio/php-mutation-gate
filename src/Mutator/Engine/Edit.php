<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use function array_slice;
use function count;
use function explode;
use function implode;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * One mutator's change to one node: the lines the node spans and the bytes of
 * the original code the change replaces, with what replaces them. The whole
 * code with the change in place is put together only when asked for, so a
 * file's edits together hold its code once.
 *
 * @internal the engine's own
 */
final readonly class Edit
{
    private function __construct(
        private Line $start,
        private Line $end,
        private string $original,
        private int $at,
        private int $length,
        private string $replacement,
    ) {
    }

    /** The change to the node on these lines, turning the original code into the mutated. */
    public static function of(Line $start, Line $end, string $original, string $mutated): self
    {
        return new self($start, $end, $original, 0, Bytes::length($original), $mutated);
    }

    /**
     * The change to the node on these lines, putting the replacement in the
     * place of so many bytes of the original code from this offset on.
     */
    public static function spliced(
        Line $start,
        Line $end,
        string $original,
        int $at,
        int $length,
        string $replacement,
    ): self {
        return new self($start, $end, $original, $at, $length, $replacement);
    }

    /** The first line of the node the change was made for. */
    public function start(): Line
    {
        return $this->start;
    }

    /** The last line of the node the change was made for. */
    public function end(): Line
    {
        return $this->end;
    }

    /** The whole code with the change in place. */
    public function mutated(): string
    {
        return sprintf(
            '%s%s%s',
            Bytes::slice($this->original, 0, $this->at),
            $this->replacement,
            Bytes::from($this->original, $this->at + $this->length),
        );
    }

    /** Whether the code with the change in place is the code as it was. */
    public function leavesCodeAsItWas(): bool
    {
        return Bytes::slice($this->original, $this->at, $this->length) === $this->replacement;
    }

    /**
     * The lines the change removes, each with `-` before it, then the lines
     * that replace them, each with `+` before it. The lines around them, the
     * same in both, are left out: those before the first line that differs,
     * then, of the rest, those after the last.
     */
    public function changed(): string
    {
        $from = Bytes::lineStartOf($this->original, $this->at);
        $to = Bytes::lineEndOf($this->original, $this->at + $this->length);
        $before = explode("\n", Bytes::slice($this->original, $from, $to - $from));
        $after = explode("\n", $this->touchedAfter($from, $to));
        $rest = Following::lines($this->original, $to);
        $first = $this->firstDiffering($before, $after, $rest);
        $shorter = min(count($before), count($after));
        $past = max(0, $first - $shorter) - $this->lastAlike($before, $after, $shorter - $first);

        return $this->marked($before, $after, $rest, $first, $past);
    }

    /** The bytes between two offsets with the change in place. */
    private function touchedAfter(int $from, int $to): string
    {
        return sprintf(
            '%s%s%s',
            Bytes::slice($this->original, $from, $this->at - $from),
            $this->replacement,
            Bytes::slice($this->original, $this->at + $this->length, $to - $this->at - $this->length),
        );
    }

    /**
     * How many lines are alike from the first on, the touched ones counted on
     * into the lines after them, until either runs out.
     *
     * @param list<string> $before
     * @param list<string> $after
     */
    private function firstDiffering(array $before, array $after, Following $rest): int
    {
        $first = 0;
        $restBefore = $rest;
        $restAfter = $rest;

        while ($this->alike($this->lineOf($before, $restBefore, $first), $this->lineOf($after, $restAfter, $first))) {
            $restBefore = $first < count($before) ? $restBefore : $restBefore->next();
            $restAfter = $first < count($after) ? $restAfter : $restAfter->next();
            $first++;
        }

        return $first;
    }

    private function alike(string|NotGiven $before, string|NotGiven $after): bool
    {
        return ! $before instanceof NotGiven && $before === $after;
    }

    /**
     * How many lines are alike from the last on, no more than so many.
     *
     * @param list<string> $before
     * @param list<string> $after
     */
    private function lastAlike(array $before, array $after, int $most): int
    {
        $last = 0;

        while ($last < $most && $before[count($before) - 1 - $last] === $after[count($after) - 1 - $last]) {
            $last++;
        }

        return $last;
    }

    /**
     * A line of the lines the change touches, or else the one of the lines
     * after them that is next; none past the last.
     *
     * @param list<string> $touched
     */
    private function lineOf(array $touched, Following $next, int $at): string|NotGiven
    {
        return $at < count($touched) ? $touched[$at] : $next->line();
    }

    /**
     * The lines from the first that differs to so many lines past the end of
     * the touched ones, or short of it where that is below none, removed and
     * then added, each marked.
     *
     * @param list<string> $before
     * @param list<string> $after
     */
    private function marked(array $before, array $after, Following $rest, int $first, int $past): string
    {
        return implode("\n", [
            ...$this->signed('-', $this->span($before, $rest, $first, count($before) + $past)),
            ...$this->signed('+', $this->span($after, $rest, $first, count($after) + $past)),
        ]);
    }

    /**
     * @param  list<string> $touched
     * @return list<string>
     */
    private function span(array $touched, Following $rest, int $from, int $to): array
    {
        $span = array_slice($touched, $from, $to - $from);
        $next = $rest;

        for ($at = count($touched); $at < $to; $at++) {
            $span = $at < $from ? $span : [...$span, $next->read()];
            $next = $next->next();
        }

        return $span;
    }

    /**
     * @param  list<string> $lines
     * @return list<string>
     */
    private function signed(string $sign, array $lines): array
    {
        $signed = [];

        foreach ($lines as $line) {
            $signed[] = sprintf('%s%s', $sign, $line);
        }

        return $signed;
    }
}
