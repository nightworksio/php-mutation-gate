<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Every path a change touched, in the order version control listed them.
 *
 * @implements IteratorAggregate<int, Change>
 */
final readonly class Changes implements Countable, IteratorAggregate
{
    /** @param list<Change> $changes */
    private function __construct(private array $changes) {}

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Change ...$changes): self
    {
        return new self(array_values($changes));
    }

    public function with(Change $change): self
    {
        return new self([...$this->changes, $change]);
    }

    public function count(): int
    {
        return count($this->changes);
    }

    /** @return Traversable<int, Change> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->changes);
    }
}
