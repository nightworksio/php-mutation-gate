<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use Closure;

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

    /**
     * How each command started ended, as {@see ends()} gives them, those this
     * names as settled, by their positions, from the ends so far, each time
     * one more has ended, let go: stopped where they run, and never started
     * where they wait, ending as stopped.
     *
     * @param Closure(array<int, Ran>): list<int> $settled
     */
    public function endsSettling(Closure $settled): ProcessEnds;

    /** Each command still running stopped with every process it started, and none started after. */
    public function stop(): void;
}
