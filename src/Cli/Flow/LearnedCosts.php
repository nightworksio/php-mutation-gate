<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Port\CostModel;

/**
 * How long each unit took, as every ledger a run read learned it with this
 * release of the gate: a timing another release measured says nothing of
 * what this one spends.
 *
 * @internal
 */
final readonly class LearnedCosts
{
    public function __construct(private Timings $timings)
    {
    }

    /** Of these units, those no ledger read timed, which only a first run can measure. */
    public function untimed(Units $units): Units
    {
        $untimed = [];

        foreach ($units as $unit) {
            if (! $this->timings->secondsFor($unit->path()) instanceof Seconds) {
                $untimed[] = $unit;
            }
        }

        return Units::of(...$untimed);
    }

    /**
     * What the cost model expects of this unit, from what every ledger read
     * learned of it, or, where none timed it, what the plan measured of its
     * first run.
     */
    public function estimated(CostModel $costs, Unit $unit, FirstRun $firstRun): Estimated
    {
        return $costs->cost($unit, $this->timings, $firstRun);
    }
}
