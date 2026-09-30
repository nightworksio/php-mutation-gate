<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Digest;
use Traversable;

/**
 * The bases runs keyed their units at, each once, the one seen most recently
 * first. A base is the digest of everything every key of a run reads.
 *
 * @implements IteratorAggregate<int, Digest>
 */
final readonly class Bases implements Countable, IteratorAggregate
{
    /** @param array<string, Digest> $bases by value, the most recently seen first */
    private function __construct(private array $bases)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These bases, the first the most recently seen; a base named twice stays where it was first named. */
    public static function of(Digest ...$bases): self
    {
        $collected = [];

        foreach ($bases as $base) {
            $collected += [$base->value() => $base];
        }

        return new self($collected);
    }

    /** These bases, with this one seen most recently of all. */
    public function seen(Digest $base): self
    {
        return self::of($base, ...array_values($this->bases));
    }

    /** These bases, and those of another after them. */
    public function and(self $other): self
    {
        return self::of(...array_values($this->bases), ...array_values($other->bases));
    }

    public function has(Digest $base): bool
    {
        return array_key_exists($base->value(), $this->bases);
    }

    public function count(): int
    {
        return count($this->bases);
    }

    /** @return Traversable<int, Digest> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->bases));
    }
}
