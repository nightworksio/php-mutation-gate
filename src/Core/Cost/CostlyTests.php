<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_values;
use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The tests that can have cost a run most, the costliest first (ADR-0230).
 *
 * @implements IteratorAggregate<int, CostlyTest>
 */
final readonly class CostlyTests implements Countable, IteratorAggregate
{
    /** @param list<CostlyTest> $items */
    private function __construct(private array $items)
    {
    }

    public static function of(CostlyTest ...$items): self
    {
        return new self(array_values($items));
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return Traversable<int, CostlyTest> */
    public function getIterator(): Traversable
    {
        yield from $this->items;
    }
}
