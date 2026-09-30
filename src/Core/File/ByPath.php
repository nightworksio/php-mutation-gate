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
 * @template-covariant T of object
 *
 * @implements IteratorAggregate<Path, T>
 */
final readonly class ByPath implements Countable, IteratorAggregate
{
    /** @param array<string, array{Path, T}> $values each path with its value, by the path */
    private function __construct(private array $values)
    {
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
