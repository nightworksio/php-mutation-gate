<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function sprintf;

/**
 * A file of a cgroup the gate reads or writes (ADR-0158).
 *
 * @internal
 */
enum CgroupKnob: string
{
    /** The processes of the cgroup, which a process joins it by. */
    case Procs = 'cgroup.procs';

    /** Kills every process of the cgroup at once. */
    case Kill = 'cgroup.kill';

    /** Whether any process runs in the cgroup. */
    case Events = 'cgroup.events';

    /** The controllers the cgroup hands down to the cgroups under it. */
    case SubtreeControl = 'cgroup.subtree_control';

    /** The most memory the cgroup's processes may hold together. */
    case MemoryMax = 'memory.max';

    /** The most swap the cgroup's processes may hold together. */
    case SwapMax = 'memory.swap.max';

    /** How many processes the cgroup may hold. */
    case PidsMax = 'pids.max';

    /** Whether an out-of-memory kill takes the whole cgroup. */
    case OomGroup = 'memory.oom.group';

    /** This file of a cgroup. */
    public function of(string $cgroup): string
    {
        return sprintf('%s/%s', $cgroup, $this->value);
    }
}
