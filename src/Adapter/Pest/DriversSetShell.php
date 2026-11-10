<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;

use NightWorksIO\MutationGate\Core\Runner\EndedProcesses;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\StartedProcesses;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * A shell whose every command starts with the coverage drivers a mutation
 * run sets (ADR-0147), so the runs the verification tail starts, and the
 * workers they fork from, run with them as the mutation run's own processes
 * do.
 *
 * @internal
 */
final readonly class DriversSetShell implements Shell, StartsCommands
{
    /** @param array<string, string> $drivers */
    private function __construct(private Shell $shell, private array $drivers)
    {
    }

    /**
     * That shell, its commands started with these drivers' variables; the
     * shell as it is where they set none.
     *
     * @param array<string, string> $drivers
     */
    public static function over(Shell $shell, array $drivers): Shell
    {
        return $drivers === [] ? $shell : new self($shell, $drivers);
    }

    public function run(Command $command, ProcessWatch $watch = new Unwatched()): Ran
    {
        return $this->shell->run($command->with($this->drivers), $watch);
    }

    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): ProcessEnds {
        return $this->shell->sideBySide($slots, $startingWithin, ...$this->set($commands));
    }

    public function started(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): StartedProcesses {
        return $this->shell instanceof StartsCommands
            ? $this->shell->started($slots, $startingWithin, ...$this->set($commands))
            : EndedProcesses::of($this->shell->sideBySide($slots, $startingWithin, ...$this->set($commands)));
    }

    public function in(string $directory): Shell
    {
        return new self($this->shell->in($directory), $this->drivers);
    }

    /**
     * @param  list<Command> $commands
     * @return list<Command>
     */
    private function set(array $commands): array
    {
        return array_map(fn(Command $command): Command => $command->with($this->drivers), $commands);
    }
}
