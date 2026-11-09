<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_values;

use Closure;

use function count;

use NightWorksIO\MutationGate\Core\Runner\EndedProcesses;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\StartedProcesses;
use NightWorksIO\MutationGate\Core\Runner\StartsProcesses;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\Processes;

/**
 * Processes that run no process: each command ends at once, as a program
 * written in PHP says, told what its place tells it. Side by side, the
 * commands take the places in turn, the first in the first place, and all
 * start within any time but none; started, they have all ended as they are
 * handed back. Each is kept, as it ran, in the order it was given. A watched
 * one is looked at once, before it ends.
 */
final class ProcessesFake implements Processes, StartsProcesses
{
    /** @var list<ProcessCommand> each command as it ran, told what its place told it */
    private array $ran = [];

    /** @var list<int> how many commands each start was given */
    private array $starts = [];

    /** @param Closure(ProcessCommand): Ran $program how each command ends */
    public function __construct(private readonly Closure $program)
    {
    }

    public function run(ProcessCommand $command, ProcessWatch $watch = new Unwatched()): Ran
    {
        $this->ran[] = $command;
        $watch->look();

        return ($this->program)($command);
    }

    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        ProcessCommand ...$commands,
    ): ProcessEnds {
        $places = [...$slots];
        $ends = [];
        $starting = $startingWithin instanceof Unlimited || $startingWithin->seconds() > 0.0 ? $commands : [];

        foreach (array_values($starting) as $at => $command) {
            $ends[] = $this->run($command->in($places[$at % count($places)]));
        }

        return ProcessEnds::of(...$ends);
    }

    public function started(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        ProcessCommand ...$commands,
    ): StartedProcesses {
        $this->starts[] = count($commands);

        return EndedProcesses::of($this->sideBySide($slots, $startingWithin, ...$commands));
    }

    /** @return list<int> how many commands each start was given, in turn */
    public function starts(): array
    {
        return $this->starts;
    }

    /** @return list<ProcessCommand> each command as it ran, in turn */
    public function ran(): array
    {
        return $this->ran;
    }
}
