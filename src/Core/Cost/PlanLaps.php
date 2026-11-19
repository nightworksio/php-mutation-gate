<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_values;
use function count;

use Countable;
use IteratorAggregate;

use function max;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use Traversable;

/**
 * The steps the plan's time went to, in the order they started (ADR-0220).
 *
 * @implements IteratorAggregate<int, PlanLap>
 */
final readonly class PlanLaps implements Countable, IteratorAggregate
{
    /** @param list<PlanLap> $laps */
    private function __construct(private array $laps)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(PlanLap ...$laps): self
    {
        return new self(array_values($laps));
    }

    /** These steps, and this one after them. */
    public function with(PlanLap $lap): self
    {
        return new self([...$this->laps, $lap]);
    }

    /** The seconds from the plan's start to the end of its last step; none where it timed none. */
    public function whole(): Seconds
    {
        $end = 0.0;

        foreach ($this->laps as $lap) {
            $end = max($end, $lap->since()->seconds() + $lap->took()->seconds());
        }

        return Seconds::of($end);
    }

    public function count(): int
    {
        return count($this->laps);
    }

    /** @return Traversable<int, PlanLap> */
    public function getIterator(): Traversable
    {
        yield from $this->laps;
    }
}
