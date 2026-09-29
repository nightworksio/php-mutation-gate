<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\CannotJudge;

use function sprintf;

use Traversable;

/**
 * The shards a run is cut into, made once and handed to every job. A plan
 * with no shards is a real answer: nothing is reached, or everything is proved.
 *
 * @implements IteratorAggregate<int, Shard>
 */
final readonly class Plan implements Countable, IteratorAggregate
{
    /** @param array<int, Shard> $shards by number, in the order they were added */
    private function __construct(private array $shards)
    {
    }

    public static function of(Shard ...$shards): self
    {
        $numbered = [];

        foreach ($shards as $shard) {
            $numbered[$shard->id()->number()] = $shard;
        }

        return new self($numbered);
    }

    public function shard(ShardId $id): Shard|CannotJudge
    {
        if (! array_key_exists($id->number(), $this->shards)) {
            return CannotJudge::because(sprintf(
                'The plan has no shard %d. It holds %d shards, so this job was not planned from it.',
                $id->number(),
                count($this->shards),
            ));
        }

        return $this->shards[$id->number()];
    }

    public function count(): int
    {
        return count($this->shards);
    }

    /** @return Traversable<int, Shard> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->shards));
    }
}
