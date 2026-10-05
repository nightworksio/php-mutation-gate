<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function ceil;
use function count;
use function max;

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
     * The units cut, in path order, into at most this many consecutive runs,
     * none empty, whose costliest run costs as little as any such cut's: the
     * least bound, no lower than the costliest unit, under which filling each
     * run in turn up to the bound takes no more runs than asked. It is found
     * by halving the gap between the costliest unit's cost and the whole
     * cost, which always holds, until no float lies between them.
     *
     * @return list<Run>
     */
    public function runs(int $count): array
    {
        $costs = array_map(static fn(Weighed $unit): float => $unit->cost()->seconds(), $this->units);
        $tooSmall = max([0.0, ...$costs]);
        $holds = $this->cost()->seconds();
        $middle = ($tooSmall + $holds) / 2;

        while ($middle > $tooSmall && $middle < $holds) {
            $fits = count($this->filledUpTo($costs, $middle)) <= $count;
            [$tooSmall, $holds] = $fits ? [$tooSmall, $middle] : [$middle, $holds];
            $middle = ($tooSmall + $holds) / 2;
        }

        return array_map(static fn(array $units): Run => Run::of(...$units), $this->filledUpTo($costs, $holds));
    }

    /**
     * The units in path order, each run filled until the next unit would take
     * it past a bound no unit costs more than.
     *
     * @param  list<float>                   $costs each unit's cost, in their order
     * @return list<list<Weighed>>
     */
    private function filledUpTo(array $costs, float $bound): array
    {
        $runs = [];
        $run = [];
        $weighed = 0.0;

        foreach ($this->units as $at => $unit) {
            if ($weighed + $costs[$at] > $bound) {
                $runs[] = $run;
                $run = [];
                $weighed = 0.0;
            }

            $run[] = $unit;
            $weighed += $costs[$at];
        }

        return $run === [] ? $runs : [...$runs, $run];
    }
}
