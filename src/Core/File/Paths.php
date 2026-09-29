<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function array_any;

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
    /** @param list<Path> $paths */
    private function __construct(private array $paths)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Path ...$paths): self
    {
        $collected = self::none();

        foreach ($paths as $path) {
            $collected = $collected->with($path);
        }

        return $collected;
    }

    public function with(Path $path): self
    {
        return $this->has($path) ? $this : new self([...$this->paths, $path]);
    }

    public function has(Path $path): bool
    {
        return array_any($this->paths, static fn(Path $held): bool => $held->equals($path));
    }

    public function count(): int
    {
        return count($this->paths);
    }

    /** @return Traversable<int, Path> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->paths);
    }
}
