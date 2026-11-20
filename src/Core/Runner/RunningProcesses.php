<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * Every process running on the machine now, which the gate stops a process
 * with every process under it by, tells the processes it spares by, and
 * reports a shard's processes by (ADR-0312).
 *
 * @internal
 */
interface RunningProcesses
{
    /** Every process running now, each with its parent, group, memory, age and name. */
    public function listed(): ProcessListing;
}
