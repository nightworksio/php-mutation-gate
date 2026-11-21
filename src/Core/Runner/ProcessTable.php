<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_diff;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * How the gate stops a process with every process under it, such as a
 * runner's workers and each mutant's own run, so none is left running on its
 * own, from the processes a listing finds under it or, where the process
 * runs in a cgroup of its own, the processes the cgroup holds (ADR-0312).
 *
 * @internal
 */
final readonly class ProcessTable
{
    /**
     * The most listings one stop takes: each freezes every process it finds,
     * so a tree that stands still is whole by its depth plus one, and a stop
     * that lists this often is one whose tree a fault keeps growing
     * (ADR-0312).
     */
    public const int LISTINGS = 32;

    /**
     * Stops a process with every process under it, so none is left running
     * on its own: the process frozen first, then each process found under it,
     * listing by listing until a listing finds none it has not frozen, so no
     * process can start another between the listing and the kill, or until
     * `LISTINGS` listings; then all of them killed, deepest first, and then
     * the whole session, which holds those whose parent ended before them. A
     * process the caller spares (SparedProcesses) is never signalled, nor is
     * the group of one.
     */
    public static function stop(
        int $pid,
        RunningProcesses $processes,
        bool $leads = false,
        SparedProcesses|NotGiven $spared = new NotGiven(),
    ): void {
        $sparing = $spared instanceof SparedProcesses ? $spared : SparedProcesses::listed($processes);

        if (! $sparing->signals($pid)) {
            return;
        }

        self::sent($sparing, Signal::Freeze, $pid);
        $frozen = [];
        $listings = 0;

        do {
            $under = $processes->listed()->descendantsOf($pid);
            $fresh = array_diff($under, $frozen);
            $frozen = [...$frozen, ...$fresh];
            $listings++;
            self::sent($sparing, Signal::Freeze, ...$fresh);
        } while ($fresh !== [] && $listings < self::LISTINGS);

        self::sent($sparing, Signal::Kill, ...[...$under, $pid]);

        if ($leads) {
            $sparing->signalGroup($pid, Signal::Kill);
        }
    }

    /**
     * Stops a process with every process its cgroup holds, however deep or
     * whoever's child, without a listing: all of them killed, the process
     * last, and then the whole session where it leads one. A process the
     * caller spares is never signalled.
     *
     * @param list<int> $held every process the cgroup holds
     */
    public static function stopHeld(int $pid, array $held, bool $leads, SparedProcesses $spared): void
    {
        if (! $spared->signals($pid)) {
            return;
        }

        self::sent($spared, Signal::Kill, ...[...array_diff($held, [$pid]), $pid]);

        if ($leads) {
            $spared->signalGroup($pid, Signal::Kill);
        }
    }

    /**
     * Kills every process left of the session a process led, which its group
     * shares, once it ended: those whose parent may have ended before them;
     * none where the caller spares that group.
     */
    public static function end(int $leader, SparedProcesses $spared): void
    {
        $spared->signalGroup($leader, Signal::Kill);
    }

    /** Sends a signal to each of these processes in turn, but to none the caller spares. */
    private static function sent(SparedProcesses $spared, Signal $signal, int ...$pids): void
    {
        foreach ($pids as $pid) {
            $spared->signal($pid, $signal);
        }
    }
}
