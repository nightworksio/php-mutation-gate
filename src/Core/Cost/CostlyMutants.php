<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_values;
use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A run's costliest mutants, the costliest first (ADR-0230).
 *
 * @implements IteratorAggregate<int, CostlyMutant>
 */
final readonly class CostlyMutants implements Countable, IteratorAggregate
{
    /** @param list<CostlyMutant> $items */
    private function __construct(private array $items)
    {
    }

    public static function of(CostlyMutant ...$items): self
    {
        return new self(array_values($items));
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return Traversable<int, CostlyMutant> */
    public function getIterator(): Traversable
    {
        yield from $this->items;
    }
}
