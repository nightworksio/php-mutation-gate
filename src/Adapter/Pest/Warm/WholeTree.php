<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use NightWorksIO\MutationGate\Core\Runner\RunningProcesses;

/**
 * A process of a Pest run's warm worker, a child, with every process under
 * it (ADR-0023), stopped by the processes the worker and its clients list
 * and signal, which need none but the gate's own classes (see ProcessTable).
 *
 * @internal
 */
final readonly class WholeTree
{
    /** The process with this id stopped, with every process under it among these processes, deepest first. */
    public static function stopped(int $pid, RunningProcesses $processes): void
    {
        ProcessTable::stop($pid, $processes);
    }
}
