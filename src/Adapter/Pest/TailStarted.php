<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\StartedProcesses;

/**
 * Runs of the tail started through their workers' clients, the workers
 * ended and their directory cleared once the runs are awaited or stopped.
 *
 * @internal
 */
final readonly class TailStarted implements StartedProcesses
{
    private function __construct(private StartedProcesses $started, private TailWorkers $workers)
    {
    }

    public static function of(StartedProcesses $started, TailWorkers $workers): self
    {
        return new self($started, $workers);
    }

    public function ends(): ProcessEnds
    {
        $ends = $this->started->ends();
        $this->workers->cleared();

        return $ends;
    }

    public function stop(): void
    {
        $this->started->stop();
        $this->workers->cleared();
    }
}
