<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Where each unmutated control a step ran spent its time, in the order they
 * ran (ADR-0167).
 *
 * @implements IteratorAggregate<int, ControlTime>
 */
final readonly class ControlTimes implements Countable, IteratorAggregate
{
    /** @param list<ControlTime> $times */
    private function __construct(private array $times)
    {
    }

    public static function of(ControlTime ...$times): self
    {
        return new self(array_values($times));
    }

    public function count(): int
    {
        return count($this->times);
    }

    /** @return Traversable<int, ControlTime> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->times);
    }
}
