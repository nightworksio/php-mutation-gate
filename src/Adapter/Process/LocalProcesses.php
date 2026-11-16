<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\StartsProcesses;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\Processes;
use Psr\Clock\ClockInterface;

/**
 * Runs programs as processes on this machine, side by side where there are
 * several places, and starts them so the gate goes on while they run, each
 * as Started runs it, timed on a clock; where they are given a saying,
 * saying in its log once a minute while any runs what runs (see Saying).
 *
 * @internal
 */
final readonly class LocalProcesses implements Processes, StartsProcesses
{
    public function __construct(private ClockInterface $clock, private Saying|NotGiven $saying = new NotGiven())
    {
    }

    public function run(ProcessCommand $command, ProcessWatch $watch = new Unwatched()): Ran
    {
        $ends = [...Started::of($this->clock, $this->saying, $watch, WorkerSlots::alone(), Unlimited::time(), $command)
            ->ends()];

        return $ends[0];
    }

    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        ProcessCommand ...$commands,
    ): ProcessEnds {
        return $this->started($slots, $startingWithin, ...$commands)->ends();
    }

    public function started(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        ProcessCommand ...$commands,
    ): Started {
        return Started::of($this->clock, $this->saying, new Unwatched(), $slots, $startingWithin, ...$commands);
    }
}
