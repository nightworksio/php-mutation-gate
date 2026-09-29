<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Unit;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Units, in the order they were added.
 *
 * @implements IteratorAggregate<int, Unit>
 */
final readonly class Units implements Countable, IteratorAggregate
{
    /** @param list<Unit> $units */
    private function __construct(private array $units) {}

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Unit ...$units): self
    {
        return new self(array_values($units));
    }

    public function with(Unit $unit): self
    {
        return new self([...$this->units, $unit]);
    }

    public function count(): int
    {
        return count($this->units);
    }

    /** @return Traversable<int, Unit> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->units);
    }
}
