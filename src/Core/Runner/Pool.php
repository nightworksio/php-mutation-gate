<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function min;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * How a runner runs a request's mutants (ADR-0023): this
 * many at once, each started as `runner.workers` says, and how long a run of
 * no test took to start on this machine, where the run measured it, which
 * each mutant's limit is laid on (ADR-0008).
 */
final readonly class Pool
{
    private function __construct(
        private ProcessCount $processes,
        private Workers $workers,
        private Seconds|Unmeasured $startUp,
        private Multiple $multiple,
        private bool $tail,
        private RunnerPace $pace,
        private bool $sized = false,
    ) {
    }

    public static function of(ProcessCount $processes, Workers $workers): self
    {
        return new self(
            $processes,
            $workers,
            Unmeasured::duration(),
            Multiple::standard(),
            tail: false,
            pace: RunnerPace::standard(),
        );
    }

    /** One mutant at a time, each in a fresh process. */
    public static function single(): self
    {
        return self::of(ProcessCount::single(), Workers::Fresh);
    }

    /** This pool, a run of no test having taken this long to start in it. */
    public function startingIn(Seconds|Unmeasured $startUp): self
    {
        return new self(
            $this->processes,
            $this->workers,
            $startUp,
            $this->multiple,
            $this->tail,
            $this->pace,
            $this->sized,
        );
    }

    /**
     * This pool, a learned `timeouts.multiple` being this one, as the plan
     * learned it from the ledger's kills; as it is where the plan learned none.
     */
    public function learning(Multiple|NotGiven $multiple): self
    {
        return $multiple instanceof Multiple
            ? new self(
                $this->processes,
                $this->workers,
                $this->startUp,
                $multiple,
                $this->tail,
                $this->pace,
                $this->sized,
            )
            : $this;
    }

    /**
     * This pool as the verification tail runs in it, survivors' runs again
     * among them (ADR-0070): each forked where `runner.workers` forks the
     * tail, and in a fresh process otherwise.
     */
    public function tail(): self
    {
        return new self(
            $this->processes,
            $this->workers->forksTheTail() ? $this->workers : Workers::Fresh,
            $this->startUp,
            $this->multiple,
            tail: true,
            pace: $this->pace,
            sized: $this->sized,
        );
    }

    /** This pool, its runner paced as `runner.recycleAfter` and `runner.warmSlack` say. */
    public function pacedBy(RunnerPace $pace): self
    {
        return new self(
            $this->processes,
            $this->workers,
            $this->startUp,
            $this->multiple,
            $this->tail,
            $pace,
            $this->sized,
        );
    }

    /**
     * This pool, each set of warm workers it boots no larger than the runs it
     * has, `local.sizedPools` (ADR-0120).
     */
    public function sizedByWork(): self
    {
        return new self(
            $this->processes,
            $this->workers,
            $this->startUp,
            $this->multiple,
            $this->tail,
            $this->pace,
            sized: true,
        );
    }

    /** How many workers a set boots for this many runs: as many as run at once, or no more than the runs, sized. */
    public function slotsFor(ProcessCount $runs): ProcessCount
    {
        return $this->sized ? ProcessCount::of(min($runs->count(), $this->processes->count())) : $this->processes;
    }

    /** How its runner paces its work. */
    public function pace(): RunnerPace
    {
        return $this->pace;
    }

    /** Whether the verification tail runs in it, rather than mutants' own runs (see tail). */
    public function isTail(): bool
    {
        return $this->tail;
    }

    /** How many mutants run at once. */
    public function processes(): ProcessCount
    {
        return $this->processes;
    }

    /** How each mutant's run starts. */
    public function workers(): Workers
    {
        return $this->workers;
    }

    /** These bounds, laid on the start-up this pool measured, a learned multiple being the one it was given. */
    public function bounding(LimitBounds $bounds): LimitBounds
    {
        return $bounds->startingIn($this->startUp)->learning($this->multiple);
    }
}
