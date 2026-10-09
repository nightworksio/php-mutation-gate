<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function array_key_first;
use function array_values;

use const INF;

use function ksort;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlot;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\Processes;
use Psr\Clock\ClockInterface;

use function usleep;

/**
 * Runs programs as processes on this machine, side by side where there are
 * several places, looking at each running one about a hundred times a second,
 * and at a watched one's watch as often, and timing each on a clock.
 */
final readonly class LocalProcesses implements Processes
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function run(ProcessCommand $command, ProcessWatch $watch = new Unwatched()): Ran
    {
        $ends = [...$this->watched($watch, WorkerSlots::alone(), Unlimited::time(), $command)];

        return $ends[0];
    }

    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        ProcessCommand ...$commands,
    ): ProcessEnds {
        return $this->watched(new Unwatched(), $slots, $startingWithin, ...$commands);
    }

    /** The commands run side by side, watched each time the running ones are looked at. */
    private function watched(
        ProcessWatch $watch,
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        ProcessCommand ...$commands,
    ): ProcessEnds {
        $startBy = $startingWithin instanceof Seconds ? $this->now() + $startingWithin->seconds() : INF;
        $places = [...$slots];
        $free = $places;
        $waiting = array_values($commands);
        $running = [];
        $ends = [];

        while ($waiting !== [] || $running !== []) {
            $waiting = $startBy === INF || $this->now() < $startBy ? $waiting : [];
            [$free, $waiting, $running, $ends] = $this->started($free, $waiting, $running, $ends);
            [$free, $running, $ended] = $this->ended($places, $free, $running);
            $ends += $ended;

            if ($ended === [] && $running !== []) {
                $watch->look();
                usleep(Polling::interval()->microseconds());
            }
        }

        ksort($ends);

        return ProcessEnds::of(...$ends);
    }

    /**
     * Each waiting command, in turn, started in a free place while
     * one is free; one that cannot start ends at once and frees its place.
     *
     * @param  array<int, WorkerSlot> $free    by position
     * @param  array<int, ProcessCommand>                                     $waiting by position
     * @param  array<int, Running>                                            $running by position
     * @param  array<int, Ran>                                                $ends    by position
     * @return array{array<int, WorkerSlot>, array<int, ProcessCommand>, array<int, Running>, array<int, Ran>}
     */
    private function started(array $free, array $waiting, array $running, array $ends): array
    {
        while ($free !== [] && $waiting !== []) {
            $place = array_key_first($free);
            $at = array_key_first($waiting);
            $started = Running::start($waiting[$at], $place, $free[$place], $this->now());
            unset($waiting[$at]);

            if ($started instanceof Ran) {
                $ends[$at] = $started;

                continue;
            }

            unset($free[$place]);
            $running[$at] = $started;
        }

        return [$free, $waiting, $running, $ends];
    }

    /**
     * Each running process that has ended by now, freeing its place.
     *
     * @param  list<WorkerSlot>                                               $places  every place, by position
     * @param  array<int, WorkerSlot> $free    by position
     * @param  array<int, Running>                                            $running by position
     * @return array{array<int, WorkerSlot>, array<int, Running>, array<int, Ran>}
     */
    private function ended(array $places, array $free, array $running): array
    {
        $ended = [];

        foreach ($running as $at => $process) {
            $end = $process->endedBy($this->now());

            if ($end instanceof NotGiven) {
                continue;
            }

            $ended[$at] = $end;
            $free[$process->place()] = $places[$process->place()];
            unset($running[$at]);
        }

        return [$free, $running, $ended];
    }

    /** The clock's reading, in seconds. */
    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
