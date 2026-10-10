<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Path;
use Traversable;

/**
 * The held units whose holding tests miss lines of them the whole suite
 * covers (ADR-0005, decision 10), each once, by its path: none of them is
 * mutated, and each fails the verdict with the lines it misses.
 *
 * @implements IteratorAggregate<int, NotCovered>
 */
final readonly class HeldMisses implements Countable, IteratorAggregate
{
    /** @param array<string, NotCovered> $misses by the held path */
    private function __construct(private array $misses)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(NotCovered ...$misses): self
    {
        $collected = [];

        foreach ($misses as $miss) {
            $collected += [$miss->unit()->path()->value() => $miss];
        }

        return new self($collected);
    }

    public function with(NotCovered $miss): self
    {
        return self::of(...$this, ...[$miss]);
    }

    public function and(self $those): self
    {
        return self::of(...$this, ...$those);
    }

    /** Whether the unit held at this path is one whose tests miss lines of it. */
    public function misses(Path $unit): bool
    {
        return array_key_exists($unit->value(), $this->misses);
    }

    public function count(): int
    {
        return count($this->misses);
    }

    /** @return Traversable<int, NotCovered> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->misses));
    }
}
