<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_diff;
use function array_key_exists;

use Closure;
use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match_all;
use function sprintf;

/**
 * Every process and the one that started it, as `ps` lists them, which a
 * shell reads to stop a process at its deadline with every process under it,
 * such as a runner's workers and each mutant's own run, so none is left
 * running on its own.
 *
 * @internal
 */
final readonly class ProcessTable
{
    /** The command that lists every process with its parent, one to a line. */
    public const array LISTING = ['ps', '-A', '-o', 'pid=', '-o', 'ppid='];

    /** A line of the listing: a process and its parent. */
    private const string ROW = '/^\s*(?<pid>\d+)\s+(?<parent>\d+)\s*$/m';

    /** The program that signals processes. */
    private const string SIGNALS = 'kill';

    /** The command that stops processes at once, before their ids. */
    private const array KILL = [self::SIGNALS, '-KILL'];

    /** The command that freezes processes where they stand, so none starts another, before their ids. */
    private const array FREEZE = [self::SIGNALS, '-STOP'];

    /** @param array<int, list<int>> $children each process's children, by its id */
    private function __construct(private array $children)
    {
    }

    /** The table in what the listing printed. */
    public static function parse(string $listing): self
    {
        preg_match_all(self::ROW, $listing, $rows);
        $children = [];

        foreach ($rows['pid'] as $row => $pid) {
            $children[(int) $rows['parent'][$row]][] = (int) $pid;
        }

        return new self($children);
    }

    /**
     * Every process under one, at any depth, each after the processes under it.
     *
     * @return list<int>
     */
    public function descendantsOf(int $pid): array
    {
        $under = [];

        foreach (array_key_exists($pid, $this->children) ? $this->children[$pid] : [] as $child) {
            $under = $child === $pid ? $under : [...$under, ...$this->descendantsOf($child), $child];
        }

        return $under;
    }

    /**
     * Stops a process with every process under it, so none is left running
     * on its own: the process frozen first, then each process found under it,
     * listing by listing until a listing finds none it has not frozen, so no
     * process can start another between the listing and the kill; then all of
     * them killed, deepest first, and then the whole session, which holds
     * those whose parent ended before them. A process the caller spares
     * (SparedProcesses) is never signalled, nor is the group of one.
     *
     * @param Closure(list<string>): string $run runs a command, and answers what it printed
     */
    public static function stop(
        int $pid,
        Closure $run,
        bool $leads = false,
        SparedProcesses|NotGiven $spared = new NotGiven(),
    ): void {
        $sparing = $spared instanceof SparedProcesses ? $spared : SparedProcesses::listed($run);

        if (! $sparing->signals($pid)) {
            return;
        }

        $run(self::signalling($sparing, self::FREEZE, $pid));
        $frozen = [];

        do {
            $under = self::parse($run(self::LISTING))->descendantsOf($pid);
            $fresh = array_diff($under, $frozen);
            $frozen = [...$frozen, ...$fresh];

            if ($fresh !== []) {
                $run(self::signalling($sparing, self::FREEZE, ...$fresh));
            }
        } while ($fresh !== []);

        $run(self::killing($sparing, ...[...$under, $pid]));

        if ($leads) {
            $run(self::ending($sparing, $pid));
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
            $run(self::ending($spared, $leader));
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
}
