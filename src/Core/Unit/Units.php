<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Unit;

use function array_any;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Path;
use Traversable;

/**
 * Units, in the order they were added.
 *
 * @implements IteratorAggregate<int, Unit>
 */
final readonly class Units implements Countable, IteratorAggregate
{
    /** @param list<Unit> $units */
    private function __construct(private array $units)
    {
    }

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

    /** These units but those at the path of one of those. */
    public function except(self $those): self
    {
        $kept = [];

        foreach ($this->units as $unit) {
            $kept = $those->has($unit->path()) ? $kept : [...$kept, $unit];
        }

        return new self($kept);
    }

    /** Whether one of these units is at this path. */
    public function has(Path $path): bool
    {
        return array_any($this->units, fn(Unit $unit): bool => $unit->path()->equals($path));
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
