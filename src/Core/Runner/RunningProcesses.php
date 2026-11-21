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

    /**
     * Whether the process with this id, or the whole group a process leads
     * where the id is that leader's negated, was sent the signal. The raw
     * send, with no refusal: only SparedProcesses calls it (ADR-0310).
     */
    public function signalledUnspared(int $target, Signal $signal): bool;
}
