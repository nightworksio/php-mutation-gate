<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_any;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Path;
use Traversable;

use function usort;

/**
 * The CI definitions that run the gate, in byte order of their paths, each
 * once: one workflow under GitHub Actions, the pipeline and the template
 * under GitLab, and none outside CI.
 *
 * @implements IteratorAggregate<int, CiDefinition>
 */
final readonly class CiDefinitions implements Countable, IteratorAggregate
{
    /** @param list<CiDefinition> $definitions */
    private function __construct(private array $definitions)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(CiDefinition ...$definitions): self
    {
        $all = self::none();

        foreach ($definitions as $definition) {
            $all = $all->with($definition);
        }

        return $all;
    }

    /** These definitions and one more; a definition at a path already here replaces it. */
    public function with(CiDefinition $definition): self
    {
        $kept = [$definition];

        foreach ($this->definitions as $held) {
            if (! $held->path()->equals($definition->path())) {
                $kept[] = $held;
            }
        }

        usort(
            $kept,
            static fn(CiDefinition $one, CiDefinition $other): int => $one->path()->value() <=> $other->path()->value(),
        );

        return new self($kept);
    }

    public function has(Path $path): bool
    {
        return array_any($this->definitions, fn(CiDefinition $definition): bool => $definition->path()->equals($path));
    }

    public function count(): int
    {
        return count($this->definitions);
    }

    /** @return Traversable<int, CiDefinition> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->definitions);
    }
}
