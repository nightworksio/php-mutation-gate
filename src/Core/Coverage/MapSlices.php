<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_values;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * The maps a plan writes from its whole map, each for some of its files
 * (ADR-0118), in the order they are asked for.
 *
 * @implements IteratorAggregate<int, MapSlice>
 */
final readonly class MapSlices implements IteratorAggregate
{
    /** @param list<MapSlice> $slices */
    private function __construct(private array $slices)
    {
    }

    public static function of(MapSlice ...$slices): self
    {
        return new self(array_values($slices));
    }

    /** These slices, with one more after them. */
    public function with(MapSlice $slice): self
    {
        return new self([...$this->slices, $slice]);
    }

    /** @return Traversable<int, MapSlice> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->slices);
    }
}
