<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The entries of a setting that is a list, in the order they were written.
 *
 * @template-covariant T
 *
 * @implements IteratorAggregate<int, T>
 */
final readonly class Listed implements Countable, IteratorAggregate
{
    /** @param list<T> $entries */
    private function __construct(private array $entries)
    {
    }

    /**
     * @template U
     *
     * @param  list<U>   $entries
     * @return self<U>
     */
    public static function of(array $entries): self
    {
        return new self($entries);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->entries);
    }
}
