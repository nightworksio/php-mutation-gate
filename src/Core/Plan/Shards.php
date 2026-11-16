<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_any;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\CannotJudge;

use function sprintf;

use Traversable;

/**
 * Shards in the order they were cut.
 *
 * @implements IteratorAggregate<int, Shard>
 */
final readonly class Shards implements Countable, IteratorAggregate
{
    private const string NO_SHARD
        = 'The plan has no shard %d. It holds %d shards, so this job was not planned from it.';

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

    /** The shard of this number, or why there is none: a job planned from another plan. */
    public function numbered(ShardId $id): Shard|CannotJudge
    {
        foreach ($this->shards as $shard) {
            if ($shard->id()->number() === $id->number()) {
                return $shard;
            }
        }

        return CannotJudge::because(sprintf(self::NO_SHARD, $id->number(), count($this->shards)));
    }

    /** Whether they pull their work, each an item listed first for one job (ADR-0134). */
    public function pulls(): bool
    {
        return array_any($this->shards, static fn(Shard $shard): bool => $shard->isPulled());
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
