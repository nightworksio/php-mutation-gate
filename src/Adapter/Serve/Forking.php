<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Serve;

use Closure;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * How a daemon of `mutation-gate serve` makes and stops its children
 * (ADR-0078). The gate's own script forks: each child is the leader of a
 * process group of its own, runs what it is handed and ends with the exit
 * code that answers, so it is stopped with every process under it.
 *
 * @internal
 */
interface Forking
{
    /**
     * The process id of a child forked to run this, which ends with what it
     * answers; none where none could be forked.
     *
     * @param Closure(): int $child
     */
    public function forked(Closure $child): int|NotGiven;

    /** Whether the child has ended, which it is then forgotten as. */
    public function hasEnded(int $child): bool;

    /** The child stopped, with every process under it. */
    public function stopped(int $child): void;

    /** This process taken out of the session that started it, so a Ctrl-C there stops only what it was meant for. */
    public function detached(): void;
}
