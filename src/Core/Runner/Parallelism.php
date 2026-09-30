<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** How a runner runs mutants side by side, which the cores of the machine it runs on make a process count. */
enum Parallelism
{
    /** One mutant at a time, whatever the machine. */
    case Serial;

    /** One mutant per core: a runner that runs as many at once as it is asked, asked for every core. */
    case PerCore;

    /** How many mutants run at once on a machine with this many cores. */
    public function processes(Processes $cores): Processes
    {
        return $this === self::PerCore ? $cores : Processes::single();
    }
}
