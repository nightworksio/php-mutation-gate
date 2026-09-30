<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function count;

use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * What a plan sets a run to do, as the PR comment shows it before the
 * verdict replaces it: the units its shards mutate, what the run is
 * expected to take, how much of that estimate rests on measured timings,
 * and the changed lines no test covers (ADR-0009, decision 3, and ADR-0019,
 * decision 11).
 */
final readonly class PlannedWork
{
    /** @param ByPath<Lines> $uncovered */
    private function __construct(
        private Plan $plan,
        private RunTime $estimate,
        private Percentage $measured,
        private ByPath $uncovered,
    ) {
    }

    /** The work of this plan, expected to take this long, the share of its cost model that was measured. */
    public static function of(Plan $plan, RunTime $estimate, Percentage $measured): self
    {
        return new self($plan, $estimate, $measured, ByPath::none());
    }

    /** This, with changed lines of a file that no test covers; none leaves it as it is. */
    public function withUncovered(Path $path, Lines $lines): self
    {
        return count($lines) === 0
            ? $this
            : new self($this->plan, $this->estimate, $this->measured, $this->uncovered->with($path, $lines));
    }

    /** Every unit the shards mutate, in the order the shards take them. */
    public function units(): Units
    {
        $units = Units::none();

        foreach ($this->plan as $shard) {
            foreach ($shard->units() as $unit) {
                $units = $units->with($unit);
            }
        }

        return $units;
    }

    /** How many shards have units to mutate. */
    public function shards(): int
    {
        $shards = 0;

        foreach ($this->plan as $shard) {
            $shards += $shard->isEmpty() ? 0 : 1;
        }

        return $shards;
    }

    /** The wall and runner time the plan expects, with each job's overhead. */
    public function estimate(): RunTime
    {
        return $this->estimate;
    }

    /** The share of the cost model's time that measured timings gave. */
    public function measured(): Percentage
    {
        return $this->measured;
    }

    /** @return ByPath<Lines> the changed lines no test covers, by file */
    public function uncovered(): ByPath
    {
        return $this->uncovered;
    }
}
