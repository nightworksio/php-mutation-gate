<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * How a runner runs a request's mutants (ADR-0023, decisions 5 and 12): this
 * many at once, each started as `runner.workers` says, and how long a run of
 * no test took to start on this machine, where the run measured it, which
 * each mutant's limit is laid on (ADR-0008, decision 2).
 */
final readonly class Pool
{
    private function __construct(
        private ProcessCount $processes,
        private Workers $workers,
        private Seconds|Unmeasured $startUp,
    ) {
    }

    public static function of(ProcessCount $processes, Workers $workers): self
    {
        return new self($processes, $workers, Unmeasured::duration());
    }

    /** One mutant at a time, each in a fresh process. */
    public static function single(): self
    {
        return new self(ProcessCount::single(), Workers::Fresh, Unmeasured::duration());
    }

    /** This pool, a run of no test having taken this long to start in it. */
    public function startingIn(Seconds|Unmeasured $startUp): self
    {
        return new self($this->processes, $this->workers, $startUp);
    }

    /** This pool, each mutant started in a fresh process: how survivors are confirmed (ADR-0023). */
    public function fresh(): self
    {
        return new self($this->processes, Workers::Fresh, $this->startUp);
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

    /** These bounds, laid on the start-up this pool measured. */
    public function bounding(LimitBounds $bounds): LimitBounds
    {
        return $bounds->startingIn($this->startUp);
    }
}
