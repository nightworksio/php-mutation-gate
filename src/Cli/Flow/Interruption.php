<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_any;

use Closure;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * What stops a budgeted run's work, besides its deadline: a change `watch`
 * sees arrive mid-run (ADR-0010). Where `local.cancelByUnit` is off, a change
 * stops the run before its next batch. Where it is on, a change drops from
 * the queue only the units whose files it moved, and halts a batch running
 * one, leaving all of its units unjudged, and the run goes on (ADR-0146). The batches
 * already run keep their results; the units no batch took are left
 * unjudged, as when the time runs out. A run with none is stopped only by
 * its deadline.
 *
 * @internal
 */
final readonly class Interruption
{
    /**
     * @param list<Closure(): bool> $arrivals each saying whether what stops the run has arrived
     * @param (Closure(): Paths)|NotGiven $moved the files moved since the run began, where a change cancels by unit
     */
    public function __construct(
        private array $arrivals = [],
        private Closure|NotGiven $moved = new NotGiven(),
        private Directory|NotGiven $project = new NotGiven(),
        private Seconds|NotGiven $every = new NotGiven(),
    ) {
    }

    /** Stopped once this says what stops the run has arrived. */
    public static function when(Closure $arrived): self
    {
        return new self([$arrived]);
    }

    /**
     * Cancelled unit by unit: each unit whose file has moved, by what this
     * says, dropped from the queue, and a batch looked at every so often.
     *
     * @param Closure(): Paths $moved
     */
    public static function byUnit(Closure $moved, Directory $project, Seconds $every): self
    {
        return new self([], $moved, $project, $every);
    }

    /** Whether what stops the run has arrived. */
    public function arrived(): bool
    {
        return array_any($this->arrivals, static fn(Closure $arrival): bool => $arrival() === true);
    }

    /**
     * The queue without the units whose files have moved; the whole queue where a change does not cancel by unit.
     *
     * @param list<Weighed> $queue
     *
     * @return list<Weighed>
     */
    public function kept(array $queue): array
    {
        if (! $this->moved instanceof Closure) {
            return $queue;
        }

        $moved = ($this->moved)();
        $kept = [];

        foreach ($queue as $weighed) {
            $kept = $moved->has($weighed->unit()->path()) ? $kept : [...$kept, $weighed];
        }

        return $kept;
    }

    /** A batch of these units, halted once a save moves one; nothing where a change does not cancel by unit. */
    public function halting(Units $batch): BatchHalt|NotGiven
    {
        return $this->project instanceof Directory && $this->every instanceof Seconds
            ? BatchHalt::armed($this->project, $batch, $this->every)
            : NotGiven::value();
    }
}
