<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Shards in the order they were cut.
 *
 * @implements IteratorAggregate<int, Shard>
 */
final readonly class Shards implements Countable, IteratorAggregate
{
    /** @param list<Shard> $shards */
    private function __construct(private array $shards)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Shard ...$shards): self
    {
        return new self(array_values($shards));
    }

    public function with(Shard $shard): self
    {
        return new self([...$this->shards, $shard]);
    }

    public function count(): int
    {
        return count($this->shards);
    }

    /** @return Traversable<int, Shard> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->shards);
    }
}
