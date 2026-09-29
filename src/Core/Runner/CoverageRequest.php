<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * What a runner is asked for a coverage map: to run the whole suite or one
 * group under coverage and leave the map in a directory, or to read the map
 * another job left there.
 */
final readonly class CoverageRequest
{
    private function __construct(
        private WholeSuite|Group $tests,
        private Path $directory,
        private bool $runs,
        private Processes $processes,
    ) {
    }

    public static function running(WholeSuite|Group $tests, Path $into): self
    {
        return new self($tests, $into, runs: true, processes: Processes::of(1));
    }

    public static function reading(Path $from): self
    {
        return new self(WholeSuite::tests(), $from, runs: false, processes: Processes::of(1));
    }

    public function across(Processes $processes): self
    {
        return clone($this, ['processes' => $processes]);
    }

    public function tests(): WholeSuite|Group
    {
        return $this->tests;
    }

    /** Where the map is left, or read from. */
    public function directory(): Path
    {
        return $this->directory;
    }

    /** Whether the suite runs, rather than a map being read. */
    public function runs(): bool
    {
        return $this->runs;
    }

    public function processes(): Processes
    {
        return $this->processes;
    }
}
