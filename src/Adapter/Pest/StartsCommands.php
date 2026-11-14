<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Runner\StartedProcesses;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * A shell that starts commands side by side and hands them back running, as
 * {@see Shell::sideBySide()} runs them, so the gate goes on while they run.
 *
 * @internal
 */
interface StartsCommands
{
    /** These commands, the first ones started in the places at once, the others as places free while awaited. */
    public function started(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): StartedProcesses;
}
