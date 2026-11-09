<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_filter;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\NotGiven;

use function usort;

/**
 * The items a job of a run whose shards pull their work takes next
 * (ADR-0134): its own list first, longest first, then the other jobs'
 * lists from their backs, the longest list left first.
 *
 * @internal
 */
final readonly class PullQueue
{
    /**
     * @param list<Shard>              $own    this job's own items left, longest first
     * @param array<int, list<Shard>>  $others each other job's items left, longest first, by its number
     */
    private function __construct(private array $own, private array $others)
    {
    }

    /** The items of a plan, as this job takes them. */
    public static function of(Plan $plan, ShardId $job): self
    {
        $own = [];
        $others = [];

        foreach ($plan as $item) {
            if ($item->worker()->number() === $job->number()) {
                $own[] = $item;
            } else {
                $others[$item->worker()->number()][] = $item;
            }
        }

        foreach ($others as $number => $items) {
            $others[$number] = self::longestFirst($items);
        }

        return new self(self::longestFirst($own), $others);
    }

    /** The next item to take: the first of its own, else the last of the longest other list; none where none is left. */
    public function next(): Shard|NotGiven
    {
        if ($this->own !== []) {
            return $this->own[0];
        }

        $longest = [];

        foreach ($this->others as $items) {
            $longest = count($items) > count($longest) ? $items : $longest;
        }

        return $longest === [] ? NotGiven::value() : $longest[count($longest) - 1];
    }

    /** The queue without this item. */
    public function without(ShardId $item): self
    {
        $others = [];

        foreach ($this->others as $number => $items) {
            $left = self::except($items, $item);

            if ($left !== []) {
                $others[$number] = $left;
            }
        }

        return new self(self::except($this->own, $item), $others);
    }

    /** The queue of its own items alone, as a job runs once its claims fail. */
    public function ownAlone(): self
    {
        return new self($this->own, []);
    }

    /**
     * @param  list<Shard> $items
     * @return list<Shard>
     */
    private static function except(array $items, ShardId $item): array
    {
        return array_values(array_filter(
            $items,
            static fn(Shard $shard): bool => $shard->id()->number() !== $item->number(),
        ));
    }

    /**
     * @param  list<Shard> $items
     * @return list<Shard>
     */
    private static function longestFirst(array $items): array
    {
        usort(
            $items,
            static fn(Shard $one, Shard $other): int => [$other->cost()->seconds(), $one->id()->number()]
                <=> [$one->cost()->seconds(), $other->id()->number()],
        );

        return $items;
    }
}
