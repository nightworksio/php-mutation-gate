<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_values;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\EndedProcesses;
use NightWorksIO\MutationGate\Core\Runner\EnvironmentRead;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\StartedProcesses;
use NightWorksIO\MutationGate\Core\Runner\StartsProcesses;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\Processes;

/**
 * Runs a command as a process in one directory, through the processes the
 * gate runs, each PHP process of it loading the gate's archive first where
 * the gate runs from one. At its deadline the process is stopped with every
 * process under it, such as Pest's paratest workers and each mutant's own
 * run.
 *
 * @internal
 */
final readonly class ProcessShell implements Shell, StartsCommands
{
    public function __construct(
        private Processes $processes,
        private string $directory,
        private ArchiveLoading|NotGiven $loading = new NotGiven(),
        private bool $longestFirst = false,
    ) {
    }

    public function in(string $directory): self
    {
        return new self($this->processes, $directory, $this->loading, $this->longestFirst);
    }

    /** This shell, starting the commands run side by side the longest-limited first, as `pest.longestFirst` says. */
    public function longestFirst(bool $longestFirst): self
    {
        return new self($this->processes, $this->directory, $this->loading, $longestFirst);
    }

    public function run(Command $command, ProcessWatch $watch = new Unwatched()): Ran
    {
        return $this->processes->run($this->processCommandOf($command), $watch);
    }

    /**
     * These commands run side by side, each end in the order the commands
     * were given; started the longest-limited first where the shell is so,
     * so the slowest is not the last to start.
     */
    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): ProcessEnds {
        $order = $this->longestFirst ? LongestFirst::of(...$commands) : LongestFirst::asGiven(...$commands);
        $ends = $this->processes->sideBySide(
            $slots,
            $startingWithin,
            ...$this->processCommandsOf(...$order->sorted(array_values($commands))),
        );

        return ProcessEnds::of(...$order->restored([...$ends]));
    }

    /** These commands started, where the processes start them; else run to their ends at once. */
    public function started(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): StartedProcesses {
        $processes = $this->processCommandsOf(...$commands);

        return $this->processes instanceof StartsProcesses
            ? $this->processes->started($slots, $startingWithin, ...$processes)
            : EndedProcesses::of($this->processes->sideBySide($slots, $startingWithin, ...$processes));
    }

    /** @return list<ProcessCommand> the commands as processes run them, in this shell's directory */
    private function processCommandsOf(Command ...$commands): array
    {
        $processes = [];

        foreach ($commands as $command) {
            $processes[] = $this->processCommandOf($command);
        }

        return $processes;
    }

    /**
     * A command as a process runs it, in this shell's directory, loading the
     * gate's archive first where it runs from one.
     */
    private function processCommandOf(Command $command): ProcessCommand
    {
        $command = $this->loading instanceof ArchiveLoading ? $this->loading->onto($command) : $command;
        $process = ProcessCommand::of($this->directory, ...$command->arguments())
            ->with(EnvironmentRead::of($command->environment()))
            ->within($command->deadline());
        $silence = $command->silence();

        return $silence instanceof SilenceLimit ? $process->silencedAfter($silence) : $process;
    }
}
