<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

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
    ) {
    }

    public static function of(ProcessCount $processes, Workers $workers): self
    {
        return new self($processes, $workers, Unmeasured::duration(), Multiple::standard());
    }

    /** One mutant at a time, each in a fresh process. */
    public static function single(): self
    {
        return new self(ProcessCount::single(), Workers::Fresh, Unmeasured::duration(), Multiple::standard());
    }

    /** This pool, a run of no test having taken this long to start in it. */
    public function startingIn(Seconds|Unmeasured $startUp): self
    {
        return new self($this->processes, $this->workers, $startUp, $this->multiple);
    }

    /**
     * This pool, a learned `timeouts.multiple` being this one, as the plan
     * learned it from the ledger's kills; as it is where the plan learned none.
     */
    public function learning(Multiple|NotGiven $multiple): self
    {
        return $multiple instanceof Multiple
            ? new self($this->processes, $this->workers, $this->startUp, $multiple)
            : $this;
    }

    /**
     * This pool as the verification tail runs in it, survivors' runs again
     * among them (ADR-0070): each forked where `runner.workers` forks the
     * tail, and in a fresh process otherwise.
     */
    public function tail(): self
    {
        return $this->workers->forksTheTail()
            ? $this
            : new self($this->processes, Workers::Fresh, $this->startUp, $this->multiple);
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
