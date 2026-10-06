<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * How a runner runs a request's mutants (ADR-0023, decisions 5 and 12): this
 * many at once, each started as `runner.workers` says.
 */
final readonly class Pool
{
    private function __construct(private ProcessCount $processes, private Workers $workers)
    {
    }

    public static function of(ProcessCount $processes, Workers $workers): self
    {
        return new self($processes, $workers);
    }

    /** One mutant at a time, each in a fresh process. */
    public static function single(): self
    {
        return new self(ProcessCount::single(), Workers::Fresh);
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
}
