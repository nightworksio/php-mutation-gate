<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\StartedProcesses;

/** Commands that had all ended as they were handed back, keeping whether they were stopped, and whether awaited. */
final class StartedFake implements StartedProcesses
{
    private bool $stopped = false;

    private bool $awaited = false;

    public function __construct(private readonly ProcessEnds $ends)
    {
    }

    public function ends(): ProcessEnds
    {
        $this->awaited = true;

        return $this->ends;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    /** @return array{bool, bool} whether they were awaited, and whether stopped */
    public function handled(): array
    {
        return [$this->awaited, $this->stopped];
    }
}
