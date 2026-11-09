<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function array_key_first;
use function array_keys;
use function array_values;
use function count;
use function getenv;

use const INF;

use function ksort;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\StartedProcesses;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlot;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use Psr\Clock\ClockInterface;

/**
 * Commands run side by side on this machine, as many at once as there are
 * places, each started in a free place in the order given: the first ones as
 * they are handed back, the others as places free while their ends are
 * awaited, looking at the running ones as soon as one prints or ends and at
 * least about a hundred times a second, and at the watch as often. Where they
 * are given a saying, it says in its log once a minute while any runs what
 * runs (see Saying). Let go, it stops every one still running.
 *
 * @internal
 */
final class Started implements StartedProcesses
{
    /** @var list<WorkerSlot> every place, by position */
    private readonly array $places;

    /** @var array<int, ProcessCommand> every command, by position */
    private readonly array $all;

    /** @var array<int, WorkerSlot> each free place, by position */
    private array $free;

    /** @var array<int, ProcessCommand> each command not yet started, by position */
    private array $waiting;

    /** @var array<int, Running> each running process, by its command's position */
    private array $running = [];

    /** @var array<int, Ran> how each command ended, by position */
    private array $ends = [];

    /** @var array<int, Ran> how each command that ended when last looked at ended, by position */
    private array $ended = [];

    private function __construct(
        private readonly ClockInterface $clock,
        private Saying|NotGiven $saying,
        private readonly ProcessWatch $watch,
        private readonly float $startBy,
        WorkerSlots $slots,
        ProcessCommand ...$commands,
    ) {
        $this->places = [...$slots];
        $this->free = $this->places;
        $this->all = array_values($commands);
        $this->waiting = $this->all;
    }

    public function __destruct()
    {
        $this->stop();
    }

    /** These commands, the first ones started in the places at once. */
    public static function of(
        ClockInterface $clock,
        Saying|NotGiven $saying,
        ProcessWatch $watch,
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        ProcessCommand ...$commands,
    ): self {
        $startBy = $startingWithin instanceof Seconds
            ? (float) $clock->now()->format('U.u') + $startingWithin->seconds()
            : INF;
        $started = new self($clock, $saying, $watch, $startBy, $slots, ...$commands);

        if ($started->isRunning()) {
            $started->step();
        }

        return $started;
    }

    public function ends(): ProcessEnds
    {
        while ($this->isRunning()) {
            if ($this->ended === [] && $this->running !== []) {
                $this->watch->look();
                Running::awaitAny(Polling::told(getenv(ChildVariable::Poll->value)), ...$this->running);
            }

            $this->step();
        }

        $ends = $this->ends;
        ksort($ends);

        return ProcessEnds::of(...$ends);
    }

    public function stop(): void
    {
        $this->waiting = [];

        foreach ($this->running as $at => $process) {
            $this->ends[$at] = $process->stop($this->now());
        }

        $this->running = [];
    }

    /** Whether any command waits to start or runs. */
    private function isRunning(): bool
    {
        return $this->waiting !== [] || $this->running !== [];
    }

    /**
     * Each waiting command started in a free place while it may still start,
     * each running process that has ended by now taken, and what runs said.
     */
    private function step(): void
    {
        $this->waiting = $this->isStarting() ? $this->waiting : [];
        $this->start();
        $this->ended = $this->endedNow();
        $this->ends += $this->ended;
        $this->saying = $this->saying instanceof Saying
            ? $this->saying->at(array_keys($this->running), $this->all, count($this->ends), $this->watch)
            : $this->saying;
    }

    /** Whether commands may still start: always, where nothing bounds when they start, else until then. */
    private function isStarting(): bool
    {
        return $this->startBy === INF || $this->now() < $this->startBy;
    }

    /** Each waiting command, in turn, started in a free place while one is free; one that cannot start ends at once. */
    private function start(): void
    {
        while ($this->free !== [] && $this->waiting !== []) {
            $place = array_key_first($this->free);
            $at = array_key_first($this->waiting);
            $started = Running::start($this->waiting[$at], $place, $this->free[$place], $this->now());
            unset($this->waiting[$at]);

            if (! $started instanceof Running) {
                $this->ends += [$at => $started];

                continue;
            }

            unset($this->free[$place]);
            $this->running += [$at => $started];
        }
    }

    /**
     * Each running process that has ended by now, freeing its place.
     *
     * @return array<int, Ran> by position
     */
    private function endedNow(): array
    {
        $ended = [];

        foreach ($this->running as $at => $process) {
            $end = $process->endedBy($this->now());

            if ($end instanceof NotGiven) {
                continue;
            }

            $ended[$at] = $end;
            $this->free[$process->place()] = $this->places[$process->place()];
            unset($this->running[$at]);
        }

        return $ended;
    }

    /** The clock's reading, in seconds. */
    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
