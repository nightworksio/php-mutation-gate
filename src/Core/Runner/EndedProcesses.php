<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * Commands that had all ended by the time they were handed back, as where
 * they were run to their ends at once.
 *
 * @internal
 */
final readonly class EndedProcesses implements StartedProcesses
{
    private function __construct(private ProcessEnds $ends)
    {
    }

    public static function of(ProcessEnds $ends): self
    {
        return new self($ends);
    }

    public function ends(): ProcessEnds
    {
        return $this->ends;
    }

    public function stop(): void
    {
    }
}
