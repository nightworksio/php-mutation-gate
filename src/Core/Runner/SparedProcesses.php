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

use NightWorksIO\MutationGate\Core\NotGiven;

use function posix_getsid;
use function posix_kill;
use function preg_match;
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
    /** The variable of the gate's own process that holds what it spares, as it was told. */
    public const string VARIABLE = 'MUTATION_GATE_SPARED';

    /**
     * The lowest id that names one process: 1 is the system's own first
     * process, 0 the caller's whole process group, and -1 every process the
     * caller may signal.
     */
    public const int LOWEST = 2;

    /** How the ids are told to a process: its own id, the processes, the groups. */
    private const string TOLD = '%d;%s;%s';

    private const string IDS = ',';

    /** A whole telling: its own id, then the processes and the groups, each a list of ids. */
    private const string WHOLE = '/^(?<self>\d+);(?<processes>[\d,]+);(?<groups>[\d,]+)$/';

    /**
     * @param array<int, true>                     $processes each spared process, by its id
     * @param array<int, true>                     $groups    each spared group or session, by its leader's id
     * @param (Closure(int, int): bool)|NotGiven $kill      what sends a signal to a process, or a group by its
     *                                                        leader's negated id, where PHP can; none where it
     *                                                        cannot, and a signal goes by the `kill` program
     */
    private function __construct(
        private int $self,
        private array $processes,
        private array $groups,
        private Closure|NotGiven $kill,
    ) {
    }

    /**
     * The processes this process spares, in a listing: itself and each
     * process above it, and as groups those, its own group and the session
     * it is in.
     */
    public static function of(ProcessListing $listing, int $self, int $session): self
    {
        $parents = $listing->parents();
        $spared = [$self => true];

        for ($pid = $self; array_key_exists($pid, $parents) && ! array_key_exists($parents[$pid], $spared);) {
            $pid = $parents[$pid];
            $spared[$pid] = true;
        }

        return new self($self, $spared, $spared + [$listing->groupOf($self) => true, $session => true], self::direct());
    }

    /** The processes this process spares, from a listing of the processes running now. */
    public static function listed(RunningProcesses $processes): self
    {
        $session = function_exists('posix_getsid') ? (int) posix_getsid(0) : 0;

        return self::of($processes->listed(), (int) getmypid(), $session);
    }

    /**
     * The processes a process was told it spares, where they were told to
     * this very process; else as it lists them.
     */
    public static function toldOr(string $told, RunningProcesses $processes): self
    {
        if (preg_match(self::WHOLE, $told, $parts) !== 1 || (int) $parts['self'] !== (int) getmypid()) {
            return self::listed($processes);
        }

        return new self(
            (int) $parts['self'],
            self::ids($parts['processes']),
            self::ids($parts['groups']),
            self::direct(),
        );
    }

    /**
     * The same processes spared, a signal sent by this, or by the `kill`
     * program where none is given.
     *
     * @param (Closure(int, int): bool)|NotGiven $kill
     */
    public function signallingWith(Closure|NotGiven $kill): self
    {
        return new self($this->self, $this->processes, $this->groups, $kill);
    }

    /** Whether this process sends a signal itself, rather than by the `kill` program. */
    public function sends(): bool
    {
        return $this->kill instanceof Closure;
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
        return $this->signals($pid) && $this->kill instanceof Closure && ($this->kill)($pid, $signal);
    }

    /** Whether the group a process with this id leads was sent the signal: never one this process spares. */
    public function signalGroup(int $leader, int $signal): bool
    {
        return $this->signalsGroup($leader) && $this->kill instanceof Closure && ($this->kill)(-$leader, $signal);
    }

    /** What sends a signal where PHP can: `posix_kill`, where PHP has it and the signals' names. */
    private static function direct(): Closure|NotGiven
    {
        return function_exists('posix_kill') && function_exists('pcntl_signal') ? posix_kill(...) : NotGiven::value();
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
