<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use function array_slice;
use function count;
use function explode;
use function implode;
use function min;

use NightWorksIO\MutationGate\Core\File\Line;

use function sprintf;

/**
 * One mutator's change to one node: the lines the node spans, the whole code
 * with the change in place, and the lines the change removes and adds.
 *
 * @internal the engine's own
 */
final readonly class Edit
{
    private function __construct(
        private Line $start,
        private Line $end,
        private string $original,
        private string $mutated,
    ) {
    }

    /** The change to the node on these lines, turning the original code into the mutated. */
    public static function of(Line $start, Line $end, string $original, string $mutated): self
    {
        return new self($start, $end, $original, $mutated);
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
        return $this->mutated;
    }

    /**
     * The lines the change removes, each with `-` before it, then the lines
     * that replace them, each with `+` before it. The lines around them, the
     * same in both, are left out.
     */
    public function changed(): string
    {
        $before = explode("\n", $this->original);
        $after = explode("\n", $this->mutated);
        $shorter = min(count($before), count($after));
        $first = 0;

        while ($first < $shorter && $before[$first] === $after[$first]) {
            $first++;
        }

        $last = 0;

        while ($last < $shorter - $first && $before[count($before) - 1 - $last] === $after[count($after) - 1 - $last]) {
            $last++;
        }

        return implode("\n", [
            ...$this->marked('-', array_slice($before, $first, count($before) - $first - $last)),
            ...$this->marked('+', array_slice($after, $first, count($after) - $first - $last)),
        ]);
    }

    /**
     * @param  list<string> $lines
     * @return list<string>
     */
    private function marked(string $sign, array $lines): array
    {
        $marked = [];

        foreach ($lines as $line) {
            $marked[] = sprintf('%s%s', $sign, $line);
        }

        return $marked;
    }
}
