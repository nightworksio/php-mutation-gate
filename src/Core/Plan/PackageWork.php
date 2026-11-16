<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_filter;
use function array_map;
use function array_values;
use function ceil;
use function count;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function usort;

/**
 * The units of one package, in path order, which its shards take in turn,
 * or each take whole, one part of the test files apiece, where the run cuts
 * its test files across its shards (ADR-0117). Packages never share a shard.
 *
 * @internal
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
     * Every unit, in path order, in as many runs as asked, each judged by its
     * own part of the test files and weighed at its share of the cost; each
     * held unit, where held units are judged whole, in one run alone, in turn,
     * weighed whole, its holding tests judging it (ADR-0162).
     *
     * @return list<Run>
     */
    public function parts(int $count, bool $heldWhole = true): array
    {
        $parts = [];
        $shared = array_values(array_filter(
            $this->units,
            static fn(Weighed $unit): bool => ! $heldWhole || ! $unit->unit()->isHeld(),
        ));
        $held = $heldWhole
            ? array_values(array_filter($this->units, static fn(Weighed $unit): bool => $unit->unit()->isHeld()))
            : [];

        for ($number = 1; $this->units !== [] && $number <= $count; $number++) {
            $whole = array_values(array_filter(
                $held,
                static fn(int $at): bool => $at % $count === $number - 1,
                ARRAY_FILTER_USE_KEY,
            ));
            $parts[] = Run::inPart(TestPart::of($number, $count), ...$shared)->holdingWhole(...$whole);
        }

        return $parts;
    }

    /**
     * The units cut, in path order, into at most this many consecutive runs,
     * none empty, whose costliest run costs as little as any such cut's, and
     * whose others cost as little as that leaves room for: the least bound
     * under which filling each run in turn up to it takes no more runs than
     * asked, a unit that costs more than the bound having a run of its own.
     * One unit's cost therefore bounds its own run and no other. It is found
     * by halving the gap between the cheapest unit's cost and the whole cost,
     * which always holds, until no float lies between them.
     *
     * @return list<Run>
     */
    public function runs(int $count): array
    {
        if ($this->units === []) {
            return [];
        }

        $costs = array_map(static fn(Weighed $unit): float => $unit->cost()->seconds(), $this->units);
        $tooSmall = min([...array_filter($costs, static fn(float $cost): bool => $cost > 0.0), max($costs)]);
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
     * it past a bound; a unit that costs nothing takes no run past it, and a
     * unit that costs more than the bound fills a run by itself.
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
            if ($run !== [] && $costs[$at] > 0.0 && $weighed + $costs[$at] > $bound) {
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
