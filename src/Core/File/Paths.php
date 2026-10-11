<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function array_diff_key;
use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Paths in the order they were added, each once.
 *
 * @implements IteratorAggregate<int, Path>
 */
final readonly class Paths implements Countable, IteratorAggregate
{
    /** @param array<string, Path> $paths by value, in the order they were added */
    private function __construct(private array $paths)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Path ...$paths): self
    {
        $collected = [];

        foreach ($paths as $path) {
            $collected += [$path->value() => $path];
        }

        return new self($collected);
    }

    public function with(Path $path): self
    {
        if ($this->has($path)) {
            return $this;
        }

        $paths = $this->paths;
        $paths[$path->value()] = $path;

        return new self($paths);
    }

    /** These paths, then those of some more these do not hold, in their order. */
    public function and(self $more): self
    {
        return new self($this->paths + $more->paths);
    }

    /** These paths, less those of some others. */
    public function without(self $left): self
    {
        return new self(array_diff_key($this->paths, $left->paths));
    }

    public function has(Path $path): bool
    {
        return array_key_exists($path->value(), $this->paths);
    }

    public function count(): int
    {
        return count($this->paths);
    }

    /** @return Traversable<int, Path> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->paths));
    }
}
