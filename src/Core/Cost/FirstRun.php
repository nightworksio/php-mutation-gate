<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/**
 * What the plan measured that a cost model can estimate a unit by before any
 * shard has timed it: the coverage run's map, where the gate's own engine
 * makes each file's mutants, how long a mutant's own run takes to start, and
 * how many mutants the runner runs at once (ADR-0006, decision 4).
 */
final readonly class FirstRun
{
    private function __construct(
        private CoverageMap $map,
        private MutantSites $sites,
        private Seconds $startUp,
        private Processes $processes,
    ) {
    }

    /** What a plan with no coverage map measured: nothing, so no unit is measured. */
    public static function unmeasured(): self
    {
        return new self(CoverageMap::empty(), MutantSites::none(), Seconds::of(0.0), Processes::single());
    }

    public static function of(CoverageMap $map, MutantSites $sites, Seconds $startUp, Processes $processes): self
    {
        return new self($map, $sites, $startUp, $processes);
    }

    /**
     * What mutating a unit is expected to take: each mutant of its files on a
     * line some test covers costs a run's start-up and every covering test's
     * time, spread over the processes the runner runs at once; one no test
     * covers costs nothing. A unit none of whose files the engine counted is
     * unmeasured.
     */
    public function seconds(Unit $unit): Seconds|Unmeasured
    {
        $counted = false;
        $seconds = 0.0;

        foreach ($this->sites->files() as $file) {
            if ($file->within($unit->path())) {
                $counted = true;
                $seconds += $this->secondsOf($file);
            }
        }

        return $counted ? Seconds::of($seconds / $this->processes->count()) : Unmeasured::duration();
    }

    public function map(): CoverageMap
    {
        return $this->map;
    }

    public function sites(): MutantSites
    {
        return $this->sites;
    }

    /** How long a mutant's own run takes to start, before its first test. */
    public function startUp(): Seconds
    {
        return $this->startUp;
    }

    public function processes(): Processes
    {
        return $this->processes;
    }

    /** One process's time for the mutants of one file. */
    private function secondsOf(Path $file): float
    {
        $seconds = 0.0;

        foreach ($this->sites->linesOf($file) as $line) {
            $seconds += $this->sites->countAt($file, $line) * $this->perMutant($file, $line);
        }

        return $seconds;
    }

    /** A mutant's run on a line: its start-up and every covering test, or nothing where no test covers it. */
    private function perMutant(Path $file, Line $line): float
    {
        $tests = $this->map->testsCovering($file, $line);
        $seconds = count($tests) === 0 ? 0.0 : $this->startUp->seconds();

        foreach ($tests as $test) {
            $duration = $this->map->durationOf($test);
            $seconds += $duration instanceof Seconds ? $duration->seconds() : 0.0;
        }

        return $seconds;
    }
}
