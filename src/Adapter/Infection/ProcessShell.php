<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_fill_keys;
use function array_filter;
use function array_key_exists;
use function array_map;

use NightWorksIO\MutationGate\Core\Runner\EnvironmentRead;
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
 * gate runs.
 *
 * - The process starts with the running PHP's directory first on the `PATH`,
 *   so the PHPUnit Infection starts for each mutant through its script's `#!`
 *   line runs on the same PHP as the gate.
 * - No inherited variable that makes PHPUnit or Infection act as a worker of
 *   another run, or makes a plugin act for the gate, reaches it unless the
 *   command sets it, and no variable the command withholds does, such as a
 *   credential of the CI or the proof store: the project's tests, and every
 *   mutant of its code, run in it.
 * - At its deadline it is stopped with every process it started.
 * - A process that cannot start ends as a failure, with the reason as its
 *   output.
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
        return $this->processes->sideBySide(
            $slots,
            $startingWithin,
            ...array_map($this->processCommandOf(...), $commands),
        );
    }

    /** A command as a process runs it: in this shell's directory, told what the process must and must not see. */
    private function processCommandOf(Command $command): ProcessCommand
    {
        return ProcessCommand::of($this->directory, ...$command->arguments())
            ->with(EnvironmentRead::of([...$this->environment($command->withheld()), ...$command->environment()]))
            ->within($command->deadline());
    }

    /**
     * Each variable the process must not inherit as false, those that make a
     * process another run's worker whether or not the environment shows them,
     * and its `PATH`.
     *
     * @return array<string, string|false>
     */
    private function environment(Withheld $withheld): array
    {
        $environment = [
            ...array_filter(
                Withholding::of($withheld->and(Withheld::otherRuns()), $this->inherited),
                static fn(string|false $value): bool => $value === false,
            ),
            ...array_fill_keys(WorkerVariable::names(), value: false),
        ];
        $path = array_key_exists(SearchPath::VARIABLE, $this->inherited) ? $this->inherited[SearchPath::VARIABLE] : '';
        $environment[SearchPath::VARIABLE] = SearchPath::phpFirst($path);

        return $environment;
    }
}
