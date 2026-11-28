<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_values;

use IteratorAggregate;
use Traversable;

/**
 * What each route cost a run, in the order a mutant may meet the routes (ADR-0230).
 *
 * @implements IteratorAggregate<int, RouteCost>
 */
final readonly class RouteCosts implements IteratorAggregate
{
    /** @param list<RouteCost> $items */
    private function __construct(private array $items)
    {
    }

    public static function of(RouteCost ...$items): self
    {
        return new self(array_values($items));
    }

    /** @return Traversable<int, RouteCost> */
    public function getIterator(): Traversable
    {
        yield from $this->items;
    }
}
