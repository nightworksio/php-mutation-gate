<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * What mutating a unit costs a runner. A cost decides which shard a unit goes
 * to, never whether its mutants run, so a wrong cost slows a runner and never
 * changes a verdict.
 */
interface CostModel
{
    /**
     * What mutating this unit is expected to take, and what that rests on:
     * the timings earlier shards measured, or, for a unit none timed, what
     * the plan measured of its first run.
     */
    public function cost(Unit $unit, Timings $learned, FirstRun $firstRun): Estimated;

    /**
     * What a finished shard teaches: each of its units' share of the time it
     * spent mutating, measured by the runner and when the measurement says.
     * The coverage map times the tests that stand in for a mutant with no
     * duration of its own.
     */
    public function learn(Units $units, Mutants $mutants, CoverageMap $coverage, Measurement $measured): Timings;
}
