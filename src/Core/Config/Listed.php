<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_key_exists;
use function array_values;

use ArrayIterator;
use Closure;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The entries of a setting that is a list, in the order they were written.
 *
 * @template-covariant T of object|string|int|float|bool
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
     * @template U of object|string|int|float|bool
     *
     * @param  U         ...$entries
     * @return self<U>
     */
    public static function of(object|string|int|float|bool ...$entries): self
    {
        return new self(array_values($entries));
    }

    /**
     * These entries, then each of a later layer's that is not one of them already, as its identity says.
     *
     * @template U of object|string|int|float|bool
     *
     * @param  self<U>                 $later
     * @param  Closure(T|U): string    $identity
     * @return self<T|U>
     */
    public function and(self $later, Closure $identity): self
    {
        $entries = $this->entries;
        $seen = [];

        foreach ($this->entries as $entry) {
            $seen[$identity($entry)] = true;
        }

        foreach ($later->entries as $entry) {
            if (! array_key_exists($identity($entry), $seen)) {
                $entries[] = $entry;
                $seen[$identity($entry)] = true;
            }
        }

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
