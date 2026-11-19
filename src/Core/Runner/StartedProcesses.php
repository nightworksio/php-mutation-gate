<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * Commands started side by side without waiting for them, each looked at,
 * and so held to its deadline and its silence limit, only while their ends
 * are awaited: one that prints more than its pipe holds waits until then.
 * Once let go, those still running are stopped.
 *
 * @internal
 */
interface StartedProcesses
{
    /**
     * How each command started ended, in the order the commands were given,
     * once every one has ended: those not yet started start as places free.
     */
    public function ends(): ProcessEnds;

    /** Each command still running stopped with every process it started, and none started after. */
    public function stop(): void;
}
