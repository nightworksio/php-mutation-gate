<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * Runs programs as processes for the runners: one to its end, or several side
 * by side. A process still running at its command's deadline is stopped with
 * every process it started, so none is left running after the gate moves on,
 * and so is one whose command has a silence limit, where the file the limit
 * names has not grown for that long since it last grew, which its end says.
 * A program that cannot be started did not succeed, and says why. Each end
 * says how long the process ran.
 */
interface Processes
{
    /** A command run to its end, or stopped at its deadline or its silence limit. */
    public function run(ProcessCommand $command): Ran;

    /**
     * Commands run side by side, as many at once as there are places: each,
     * in the order given, starts in a free place, the first ones in the
     * places' order, and is told what that place tells it, and no place runs
     * two at once. A command not
     * started within the time given never starts. The ends of those started
     * come back in the order the commands were given, so the commands left
     * unstarted are those after the last end.
     */
    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        ProcessCommand ...$commands,
    ): ProcessEnds;
}
