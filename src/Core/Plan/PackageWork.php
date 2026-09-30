<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_key_last;
use function array_last;
use function array_map;
use function array_slice;
use function ceil;
use function count;

use NightWorksIO\MutationGate\Core\Time\Seconds;

use function usort;

/**
 * The units of one package, in path order, which its shards take in turn.
 * Packages never share a shard.
 */
final readonly class PackageWork
{
    /** @param list<Weighed> $units in path order */
    private function __construct(private array $units)
    {
    }

    public static function of(Weighed ...$units): self
    {
        $sorted = [...$units];
        usort(
            $sorted,
            static fn(Weighed $one, Weighed $other): int => $one->unit()->path()->value()
                <=> $other->unit()->path()->value(),
        );

        return new self($sorted);
    }

    public function cost(): Seconds
    {
        return Run::of(...$this->units)->cost();
    }

    /** How many shards of about this many seconds the package fills: at least one. */
    public function shardsAt(float $size): int
    {
        $cost = $this->cost()->seconds();

        return $cost > 0.0 && $size > 0.0 ? (int) ceil($cost / $size) : 1;
    }

    /**
     * The units cut, in path order, into consecutive runs of about equal
     * cost. Each cut falls on the first unit that takes its run past an equal
     * share of the total, so no run is empty, and there are at most as many
     * runs as asked.
     *
     * @return list<Run>
     */
    public function runs(int $count): array
    {
        $share = $this->cost()->seconds() / $count;
        $runs = [[]];
        $weighed = 0.0;

        foreach ($this->units as $unit) {
            $runs[array_key_last($runs)][] = $unit;
            $weighed += $unit->cost()->seconds();

            if (count($runs) < $count && $weighed >= $share * count($runs)) {
                $runs[] = [];
            }
        }

        $cut = array_last($runs) === [] ? array_slice($runs, 0, -1) : $runs;

        return array_map(static fn(array $units): Run => Run::of(...$units), $cut);
    }
}
