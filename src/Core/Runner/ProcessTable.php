<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_diff;

use Closure;
use NightWorksIO\MutationGate\Core\NotGiven;

use const SIGKILL;
use const SIGSTOP;

use function sprintf;

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

    /** The program that signals processes. */
    private const string SIGNALS = 'kill';

    /** The command that stops processes at once, before their ids. */
    private const array KILL = [self::SIGNALS, '-KILL'];

    /** The command that freezes processes where they stand, so none starts another, before their ids. */
    private const array FREEZE = [self::SIGNALS, '-STOP'];

    /**
     * Stops a process with every process under it, so none is left running
     * on its own: the process frozen first, then each process found under it,
     * listing by listing until a listing finds none it has not frozen, so no
     * process can start another between the listing and the kill, or until
     * `LISTINGS` listings; then all of them killed, deepest first, and then
     * the whole session, which holds those whose parent ended before them. A
     * process the caller spares (SparedProcesses) is never signalled, nor is
     * the group of one.
     *
     * @param Closure(list<string>): string $run runs a command, and answers what it printed
     */
    public static function stop(
        int $pid,
        Closure $run,
        RunningProcesses $processes,
        bool $leads = false,
        SparedProcesses|NotGiven $spared = new NotGiven(),
    ): void {
        $sparing = $spared instanceof SparedProcesses ? $spared : SparedProcesses::listed($processes);

        if (! $sparing->signals($pid)) {
            return;
        }

        self::sent($sparing, $run, self::FREEZE, $pid);
        $frozen = [];
        $listings = 0;

        do {
            $under = $processes->listed()->descendantsOf($pid);
            $fresh = array_diff($under, $frozen);
            $frozen = [...$frozen, ...$fresh];
            $listings++;

            if ($fresh !== []) {
                self::sent($sparing, $run, self::FREEZE, ...$fresh);
            }
        } while ($fresh !== [] && $listings < self::LISTINGS);

        self::sent($sparing, $run, self::KILL, ...[...$under, $pid]);

        if ($leads) {
            self::ended($sparing, $run, $pid);
        }
    }

    /**
     * Stops a process with every process its cgroup holds, however deep or
     * whoever's child, without a listing: all of them killed, the process
     * last, and then the whole session where it leads one. A process the
     * caller spares is never signalled.
     *
     * @param list<int>                     $held every process the cgroup holds
     * @param Closure(list<string>): string $run  runs a command, and answers what it printed
     */
    public static function stopHeld(int $pid, array $held, Closure $run, bool $leads, SparedProcesses $spared): void
    {
        if (! $spared->signals($pid)) {
            return;
        }

        self::sent($spared, $run, self::KILL, ...[...array_diff($held, [$pid]), $pid]);

        if ($leads) {
            self::ended($spared, $run, $pid);
        }
    }

    /**
     * Kills every process left of the session a process led, which its group
     * shares, once it ended: those whose parent may have ended before them.
     *
     * @param Closure(list<string>): string $run runs a command, and answers what it printed
     */
    public static function end(int $leader, Closure $run, SparedProcesses $spared): void
    {
        if ($spared->signalsGroup($leader)) {
            self::ended($spared, $run, $leader);
        }
    }

    /**
     * The command that kills every process of the group a process leads at
     * once; nothing where the caller spares that group.
     *
     * @return list<string>
     */
    public static function ending(SparedProcesses $spared, int $leader): array
    {
        return $spared->signalsGroup($leader) ? [...self::KILL, '--', sprintf('-%d', $leader)] : self::KILL;
    }

    /**
     * The command that stops these processes at once, leaving out each one
     * the caller spares.
     *
     * @return list<string>
     */
    public static function killing(SparedProcesses $spared, int ...$pids): array
    {
        return self::signalling($spared, self::KILL, ...$pids);
    }

    /**
     * @param  list<string> $command
     * @return list<string>
     */
    private static function signalling(SparedProcesses $spared, array $command, int ...$pids): array
    {
        $named = [];

        foreach ($pids as $pid) {
            $named = $spared->signals($pid) ? [...$named, sprintf('%d', $pid)] : $named;
        }

        return [...$command, ...$named];
    }

    /**
     * Sends a signal to these processes, but to none the caller spares:
     * itself where it can, else by the `kill` program this command starts.
     *
     * @param list<string>                  $command
     * @param Closure(list<string>): string $run
     */
    private static function sent(SparedProcesses $spared, Closure $run, array $command, int ...$pids): void
    {
        if (! $spared->sends()) {
            $run(self::signalling($spared, $command, ...$pids));

            return;
        }

        foreach ($pids as $pid) {
            $spared->signal($pid, $command === self::FREEZE ? SIGSTOP : SIGKILL);
        }
    }

    /**
     * Kills every process of the group a process leads at once, but not one
     * the caller spares: itself where it can, else by the `kill` program.
     *
     * @param Closure(list<string>): string $run
     */
    private static function ended(SparedProcesses $spared, Closure $run, int $leader): void
    {
        if (! $spared->sends()) {
            $run(self::ending($spared, $leader));

            return;
        }

        $spared->signalGroup($leader, SIGKILL);
    }
}
