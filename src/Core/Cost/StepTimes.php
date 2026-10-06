<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_map;
use function array_values;
use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use Traversable;

/**
 * The steps a shard's time went to, in the order they started.
 *
 * @implements IteratorAggregate<int, StepTime>
 */
final readonly class StepTimes implements Countable, IteratorAggregate
{
    /** @param list<StepTime> $steps */
    private function __construct(private array $steps)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(StepTime ...$steps): self
    {
        return new self(array_values($steps));
    }

    /** These steps, and those after them. */
    public function and(self $after): self
    {
        return new self([...$this->steps, ...$after->steps]);
    }

    /** These steps, each started this much later: timed from an earlier start than their own. */
    public function later(Seconds $by): self
    {
        return new self(array_map(static fn(StepTime $step): StepTime => $step->later($by), $this->steps));
    }

    public function count(): int
    {
        return count($this->steps);
    }

    /** @return Traversable<int, StepTime> */
    public function getIterator(): Traversable
    {
        yield from $this->steps;
    }
}
