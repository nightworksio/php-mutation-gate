<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Native\PlacedMutant;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * One mutant's fresh Pest run, ready to start (ADR-0148): the mutant, the
 * tests that cover it, the files the gate's wrapper reads and writes for it,
 * the command that runs its tests with the mutant served, the same command
 * serving the file unmutated, the JUnit log the run writes, its limit, and
 * whether it selects its tests by a filter or runs every test it loads.
 *
 * @internal
 */
final readonly class FreshRun
{
    private function __construct(
        private Mutant $mutant,
        private TestIds $covering,
        private PlacedMutant $files,
        private Command $command,
        private Command $control,
        private string $log,
        private Seconds $limit,
        private bool $filtered,
    ) {
    }

    public static function of(
        Mutant $mutant,
        TestIds $covering,
        PlacedMutant $files,
        Command $command,
        Command $control,
        string $log,
        Seconds $limit,
        bool $filtered,
    ): self {
        return new self($mutant, $covering, $files, $command, $control, $log, $limit, $filtered);
    }

    public function mutant(): Mutant
    {
        return $this->mutant;
    }

    public function covering(): TestIds
    {
        return $this->covering;
    }

    public function files(): PlacedMutant
    {
        return $this->files;
    }

    /** The run of its covering tests with the mutant served in its file's place. */
    public function command(): Command
    {
        return $this->command;
    }

    /** The same run serving its file unmutated, which vouches for a kill no test is named for. */
    public function control(): Command
    {
        return $this->control;
    }

    /** What the run's JUnit log holds; false where it wrote none. */
    public function logged(): string|false
    {
        return is_file($this->log) ? file_get_contents($this->log) : false;
    }

    public function limit(): Seconds
    {
        return $this->limit;
    }

    /** Whether it selects its covering tests by a filter; one that does not runs every test it loads. */
    public function isFiltered(): bool
    {
        return $this->filtered;
    }
}
