<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function array_values;

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
    private function __construct(private Shell $shell, private CoverageDrivers $drivers)
    {
    }

    /**
     * That shell, its commands started with these drivers; the shell as it
     * is where they set none.
     */
    public static function over(Shell $shell, CoverageDrivers $drivers): Shell
    {
        return $drivers->isNone() ? $shell : new self($shell, $drivers);
    }

    public function run(Command $command, ProcessWatch $watch = new Unwatched()): Ran
    {
        return $this->shell->run($this->drivers->onto($command), $watch);
    }

    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): ProcessEnds {
        return $this->shell->sideBySide($slots, $startingWithin, ...$this->set(array_values($commands)));
    }

    public function started(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): StartedProcesses {
        $set = $this->set(array_values($commands));

        return $this->shell instanceof StartsCommands
            ? $this->shell->started($slots, $startingWithin, ...$set)
            : EndedProcesses::of($this->shell->sideBySide($slots, $startingWithin, ...$set));
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
        return array_map($this->drivers->onto(...), $commands);
    }
}
