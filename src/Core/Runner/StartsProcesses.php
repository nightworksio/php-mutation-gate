<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * Processes that start commands side by side and hand them back running, as
 * {@see \NightWorksIO\MutationGate\Port\Processes::sideBySide()} runs them,
 * so the gate goes on while they run.
 *
 * @internal
 */
interface StartsProcesses
{
    /** These commands, the first ones started in the places at once, the others as places free while awaited. */
    public function started(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        ProcessCommand ...$commands,
    ): StartedProcesses;
}
