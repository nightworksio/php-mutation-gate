<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_key_exists;
use function array_keys;

use Closure;

use function explode;
use function function_exists;
use function getmypid;
use function implode;
use function posix_getsid;
use function posix_kill;
use function preg_match;
use function preg_match_all;
use function sprintf;

/**
 * The processes one process never signals, whatever its code asks: the
 * system's first process and every id under 2, itself, each process above
 * it, and the group and the session it is in, so no mutant of the gate's
 * own signalling, nor a fault in it, stops the gate, the shell or CI agent
 * that started it, or a process beside it in its group (ADR-0310).
 *
 * @internal
 */
final readonly class SparedProcesses
{
    /** The command that lists every process with its parent and its group, one to a line. */
    public const array LISTING = [...ProcessTable::LISTING, '-opgid='];

    /** The variable of the gate's own process that holds what it spares, as it was told. */
    public const string VARIABLE = 'MUTATION_GATE_SPARED';

    /**
     * The lowest id that names one process: 1 is the system's own first
     * process, 0 the caller's whole process group, and -1 every process the
     * caller may signal.
     */
    public const int LOWEST = 2;

    /** A line of the listing: a process, its parent and its group. */
    private const string ROW = '/^\s*(?<pid>\d+)\s+(?<parent>\d+)\s+(?<group>\d+)\s*$/m';

    /** How the ids are told to a process: its own id, the processes, the groups. */
    private const string TOLD = '%d;%s;%s';

    private const string IDS = ',';

    /** A whole telling: its own id, then the processes and the groups, each a list of ids. */
    private const string WHOLE = '/^(?<self>\d+);(?<processes>[\d,]+);(?<groups>[\d,]+)$/';

    /**
     * @param array<int, true> $processes each spared process, by its id
     * @param array<int, true> $groups    each spared group or session, by its leader's id
     */
    private function __construct(private int $self, private array $processes, private array $groups)
    {
    }

    /**
     * The processes this process spares, in what the listing printed: itself
     * and each process above it, and as groups those, its own group and the
     * session it is in.
     */
    public static function of(string $listing, int $self, int $session): self
    {
        preg_match_all(self::ROW, $listing, $rows);
        $parents = [];
        $groups = [];

        foreach ($rows['pid'] as $row => $pid) {
            $parents[(int) $pid] = (int) $rows['parent'][$row];
            $groups[(int) $pid] = (int) $rows['group'][$row];
        }

        $spared = [$self => true];

        for ($pid = $self; array_key_exists($pid, $parents) && ! array_key_exists($parents[$pid], $spared);) {
            $pid = $parents[$pid];
            $spared[$pid] = true;
        }

        $own = array_key_exists($self, $groups) ? [$groups[$self] => true] : [];

        return new self($self, $spared, $spared + $own + [$session => true]);
    }

    /**
     * The processes this process spares, from a listing it runs.
     *
     * @param Closure(list<string>): string $run runs a command, and answers what it printed
     */
    public static function listed(Closure $run): self
    {
        $session = function_exists('posix_getsid') ? (int) posix_getsid(0) : 0;

        return self::of($run(self::LISTING), (int) getmypid(), $session);
    }

    /**
     * The processes a process was told it spares, where they were told to
     * this very process; else as it lists them.
     *
     * @param Closure(list<string>): string $run runs a command, and answers what it printed
     */
    public static function toldOr(string $told, Closure $run): self
    {
        if (preg_match(self::WHOLE, $told, $parts) !== 1 || (int) $parts['self'] !== (int) getmypid()) {
            return self::listed($run);
        }

        return new self((int) $parts['self'], self::ids($parts['processes']), self::ids($parts['groups']));
    }

    /** The ids as they are told to the process that spares them. */
    public function told(): string
    {
        return sprintf(
            self::TOLD,
            $this->self,
            implode(self::IDS, array_keys($this->processes)),
            implode(self::IDS, array_keys($this->groups)),
        );
    }

    /** Whether this process may signal the process with this id. */
    public function signals(int $pid): bool
    {
        return $pid >= self::LOWEST && ! array_key_exists($pid, $this->processes);
    }

    /** Whether this process may signal the whole group a process with this id leads. */
    public function signalsGroup(int $leader): bool
    {
        return $leader >= self::LOWEST && ! array_key_exists($leader, $this->groups);
    }

    /** Whether the process with this id was sent the signal: never one this process spares. */
    public function signal(int $pid, int $signal): bool
    {
        return $this->signals($pid) && posix_kill($pid, $signal);
    }

    /** Whether the group a process with this id leads was sent the signal: never one this process spares. */
    public function signalGroup(int $leader, int $signal): bool
    {
        return $this->signalsGroup($leader) && posix_kill(-$leader, $signal);
    }

    /** @return array<int, true> */
    private static function ids(string $listed): array
    {
        $ids = [];

        foreach (explode(self::IDS, $listed) as $id) {
            $ids[(int) $id] = true;
        }

        return $ids;
    }
}
