<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * What mutating a unit costs a runner. A cost decides which shard a unit goes
 * to, never whether its mutants run, so a wrong cost slows a runner and never
 * changes a verdict.
 */
interface CostModel
{
    /** What mutating this unit is expected to take, given the timings earlier shards measured. */
    public function cost(Unit $unit, Timings $learned): Seconds;

    /** What a finished shard teaches: each of its units' share of the time it spent mutating. */
    public function learn(Units $units, Mutants $mutants, Seconds $spent): Timings;
}
