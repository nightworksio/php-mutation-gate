<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_map;
use function array_values;
use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\NotGiven;
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

    /**
     * How many times the wall seconds of forking every mutant they streamed
     * at each covering test the second passes of split streams took: their
     * seconds over their bounds, each pass that ended and knew its bound;
     * none where none did (ADR-0196).
     */
    public function secondPassFactor(): float|NotGiven
    {
        $took = 0.0;
        $bound = 0.0;

        foreach ($this->steps as $step) {
            $most = $step->bound();

            if ($step->step() === Step::SecondPass && $most instanceof Seconds && $most->seconds() > 0.0) {
                $took += $step->took()->seconds();
                $bound += $most->seconds();
            }
        }

        return $bound > 0.0 ? $took / $bound : NotGiven::value();
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
