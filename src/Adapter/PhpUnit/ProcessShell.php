<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_fill_keys;
use function array_key_exists;
use function array_map;

use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\Runner\EnvironmentRead;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\SearchPath;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Runner\WorkerVariable;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\Processes;

/**
 * Runs a command as a process in one directory, through the processes the
 * gate runs, with the running PHP's directory first on the `PATH`, and says
 * how long it took.
 *
 * - No inherited variable that makes a process a worker or a mutant of
 *   another run reaches it, nor any the command withholds, such as a
 *   credential of the CI: the project's tests, and every mutant of its code,
 *   run in it. What the command sets, it gets.
 * - Where the command scans a memory cap's directory, its PHP processes scan
 *   it after the directories the inherited `PHP_INI_SCAN_DIR` names, or
 *   PHP's own where it names none (ADR-0004, decision 9).
 * - At its deadline it is stopped, and so is every process it started.
 * - A process that cannot start, or fails while running, ends as a failure,
 *   with the reason as its output.
 */
final readonly class ProcessShell implements Shell
{
    /** @param array<string, string> $inherited the environment the gate runs in */
    public function __construct(private Processes $processes, private string $directory, private array $inherited)
    {
    }

    public function in(string $directory): self
    {
        return new self($this->processes, $directory, $this->inherited);
    }

    public function run(Command $command): Ran
    {
        return $this->processes->run($this->processCommandOf($command));
    }

    public function sideBySide(WorkerSlots $slots, Seconds|Unlimited $startingWithin, Command ...$commands): ProcessEnds
    {
        $processes = [];

        foreach ($commands as $command) {
            $processes[] = $this->processCommandOf($command);
        }

        return $this->processes->sideBySide($slots, $startingWithin, ...$processes);
    }

    /** A command as a process runs it: in this shell's directory, told what the process must and must not see. */
    public function processCommandOf(Command $command): ProcessCommand
    {
        return ProcessCommand::of($this->directory, ...$command->arguments())
            ->with(EnvironmentRead::of([
                ...$this->scrubbed($command->withheld()),
                ...$this->unset(),
                ...$command->environment(),
                ...$this->capped($command),
            ]))
            ->within($command->deadline());
    }

    /**
     * Every variable the process inherits, each it must not see as false, and
     * the `PATH` it starts with.
     *
     * @return array<string, string|false>
     */
    private function scrubbed(Withheld $withheld): array
    {
        $environment = Withholding::of($withheld->and(Withheld::otherRuns()), $this->inherited);
        $inherited = array_key_exists(SearchPath::VARIABLE, $this->inherited)
            ? $this->inherited[SearchPath::VARIABLE]
            : '';
        $environment[SearchPath::VARIABLE] = SearchPath::phpFirst($inherited);

        return $environment;
    }

    /**
     * The variables the gate sets for its extension and its wrapper, and
     * those that make a process another run's worker, each unset whether or
     * not the environment holds it, since a process also inherits what
     * `$_ENV` holds, which `getenv()` does not show. Only the command that
     * needs one sets it.
     *
     * @return array<string, false>
     */
    private function unset(): array
    {
        $names = array_map(static fn(Variable $variable): string => $variable->value, Variable::cases());

        return array_fill_keys([...$names, ...WorkerVariable::names()], value: false);
    }

    /** @return array<string, string> the directories PHP scans for ini files, where the command is capped */
    private function capped(Command $command): array
    {
        $directory = $command->scanned();
        $inherited = array_key_exists(MemoryCap::SCAN_DIR, $this->inherited)
            ? $this->inherited[MemoryCap::SCAN_DIR]
            : false;

        return $directory instanceof DiskPath
            ? [MemoryCap::SCAN_DIR => MemoryCap::scanning($inherited, $directory->value())]
            : [];
    }
}
