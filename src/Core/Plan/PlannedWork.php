<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * What a plan sets a run to do, as the PR comment shows it before the
 * verdict replaces it: the units its shards mutate, what the run is
 * expected to take, and how much of that estimate rests on measured timings
 * (ADR-0009, decision 3, and ADR-0019, decision 11).
 */
final readonly class PlannedWork
{
    private function __construct(private Plan $plan, private RunTime $estimate, private Percentage $measured)
    {
    }

    /** The work of this plan, expected to take this long, the share of its cost model that was measured. */
    public static function of(Plan $plan, RunTime $estimate, Percentage $measured): self
    {
        return new self($plan, $estimate, $measured);
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
}
