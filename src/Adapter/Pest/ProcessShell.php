<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Runner\EnvironmentRead;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\Processes;

/**
 * Runs a command as a process in one directory, through the processes the
 * gate runs. At its deadline the process is stopped with every process under
 * it, such as Pest's paratest workers and each mutant's own run.
 */
final readonly class ProcessShell implements Shell
{
    public function __construct(private Processes $processes, private string $directory)
    {
    }

    public function in(string $directory): self
    {
        return new self($this->processes, $directory);
    }

    public function run(Command $command): Ran
    {
        return $this->processes->run($this->processCommandOf($command));
    }

    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): ProcessEnds {
        $processes = [];

        foreach ($commands as $command) {
            $processes[] = $this->processCommandOf($command);
        }

        return $this->processes->sideBySide($slots, $startingWithin, ...$processes);
    }

    /** A command as a process runs it, in this shell's directory. */
    private function processCommandOf(Command $command): ProcessCommand
    {
        return ProcessCommand::of($this->directory, ...$command->arguments())
            ->with(EnvironmentRead::of($command->environment()))
            ->within($command->deadline());
    }
}
