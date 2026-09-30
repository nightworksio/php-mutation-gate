<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function array_key_exists;

use Closure;

use function count;

use Countable;
use Generator;
use IteratorAggregate;

/**
 * One value for each of some paths, in the order the paths came.
 *
 * @template-covariant T of object = never
 *
 * @implements IteratorAggregate<Path, T>
 */
final readonly class ByPath implements Countable, IteratorAggregate
{
    /** @param array<string, array{Path, T}> $values each path with its value, by the path */
    private function __construct(private array $values)
    {
    }

    /** @return self<never> */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Each of these paths, with the value this gives it.
     *
     * @template V of object
     *
     * @param  Closure(Path): V $valueOf
     * @return self<V>
     */
    public static function mapping(Paths $paths, Closure $valueOf): self
    {
        $values = [];

        foreach ($paths as $path) {
            $values[$path->value()] = [$path, $valueOf($path)];
        }

        return new self($values);
    }

    /**
     * These, with a value for a path, in place of any it had, which keeps
     * the path where it came.
     *
     * @template V of object
     *
     * @param  V           $value
     * @return self<T|V>
     */
    public function with(Path $path, object $value): self
    {
        $values = $this->values;
        $values[$path->value()] = [$path, $value];

        return new self($values);
    }

    /**
     * These and the others' values, a later value of a path replacing an
     * earlier one where the earlier came.
     *
     * @template V of object
     *
     * @param  self<V>   ...$others
     * @return self<T|V>
     */
    public function and(self ...$others): self
    {
        $values = $this->values;

        foreach ($others as $other) {
            foreach ($other->values as $key => $value) {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    /**
     * The value of a path, or this where the path has none.
     *
     * @template D of object
     *
     * @param  D   $otherwise
     * @return T|D
     */
    public function at(Path $path, object $otherwise): object
    {
        return array_key_exists($path->value(), $this->values) ? $this->values[$path->value()][1] : $otherwise;
    }

    public function paths(): Paths
    {
        $paths = [];

        foreach ($this->values as [$path]) {
            $paths[] = $path;
        }

        return Paths::of(...$paths);
    }

    public function count(): int
    {
        return count($this->values);
    }

    /** @return Generator<Path, T> */
    public function getIterator(): Generator
    {
        foreach ($this->values as [$path, $value]) {
            yield $path => $value;
        }
    }
}
