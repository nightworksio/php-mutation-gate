<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_key_exists;
use function count;
use function min;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;

/**
 * What the plan measured that a cost model can estimate a unit by before any
 * shard has timed it: the coverage run's map, where the gate's own engine
 * makes each file's mutants, how long a mutant's own run takes to start, and
 * how many mutants the runner runs at once (ADR-0006). Each
 * counted file's time is reckoned once, as the run is made.
 */
final readonly class FirstRun
{
    /** How many covering tests of average time a killed mutant's run is expected to take. */
    private const int TESTS_BEFORE_A_KILL = 5;

    /** The share of mutants expected to survive and run every covering test. */
    private const float SURVIVING = 0.02;

    /** @param array<string, float> $perFile one process's seconds for each counted file's mutants, by path */
    private function __construct(
        private CoverageMap $map,
        private MutantSites $sites,
        private Seconds $startUp,
        private ProcessCount $processes,
        private array $perFile,
    ) {
    }

    /** What a plan that measured nothing knows: no unit is measured. */
    public static function unmeasured(): self
    {
        return new self(CoverageMap::of(), MutantSites::none(), Seconds::of(0.0), ProcessCount::single(), []);
    }

    public static function of(CoverageMap $map, MutantSites $sites, Seconds $startUp, ProcessCount $processes): self
    {
        $perFile = [];

        foreach ($sites->files() as $file) {
            $perFile[$file->value()] = self::secondsOf($map, $sites, $startUp, $file);
        }

        return new self($map, $sites, $startUp, $processes, $perFile);
    }

    /**
     * What mutating a unit is expected to take: each mutant of its files on a
     * line some test covers costs a run's start-up and what its covering tests
     * are expected to spend, spread over the processes the runner runs at
     * once; one no test covers costs nothing. A unit none of whose files the engine counted is
     * unmeasured. A file unit is looked up; a held unit sums the counted files
     * its path holds.
     */
    public function seconds(Unit $unit): Seconds|Unmeasured
    {
        $counted = $unit->isHeld() ? $this->under($unit->path()) : $this->file($unit->path());

        return $counted instanceof Unmeasured ? $counted : Seconds::of($counted / $this->processes->count());
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

    public function processes(): ProcessCount
    {
        return $this->processes;
    }

    private function file(Path $path): float|Unmeasured
    {
        return array_key_exists($path->value(), $this->perFile)
            ? $this->perFile[$path->value()]
            : Unmeasured::duration();
    }

    /** Every counted file a held unit's path holds, or unmeasured where it holds none. */
    private function under(Path $held): float|Unmeasured
    {
        $seconds = Unmeasured::duration();

        foreach ($this->sites->files() as $file) {
            if ($file->within($held)) {
                $seconds = ($seconds instanceof Unmeasured ? 0.0 : $seconds) + $this->perFile[$file->value()];
            }
        }

        return $seconds;
    }

    /** One process's time for the mutants of one file. */
    private static function secondsOf(CoverageMap $map, MutantSites $sites, Seconds $startUp, Path $file): float
    {
        $seconds = 0.0;

        foreach ($sites->linesOf($file) as $line) {
            $seconds += $sites->countAt($file, $line) * self::perMutant($map, $startUp, $file, $line);
        }

        return $seconds;
    }

    /**
     * A mutant's run on a line: its start-up and what its covering tests are
     * expected to spend, or nothing where no test covers it. A run stops at
     * the first test that kills, and most mutants are killed, whatever the
     * number of tests that cover them, so most stop within a few tests of
     * average time; a survivor runs them all.
     */
    private static function perMutant(CoverageMap $map, Seconds $startUp, Path $file, Line $line): float
    {
        $tests = $map->testsCovering($file, $line, $line);
        $count = count($tests);
        $every = 0.0;

        foreach ($tests as $test) {
            $duration = $map->durationOf($test);
            $every += $duration instanceof Seconds ? $duration->seconds() : 0.0;
        }

        $killed = $count === 0 ? 0.0 : min($every, self::TESTS_BEFORE_A_KILL * $every / $count);

        return $count === 0
            ? 0.0
            : $startUp->seconds() + (1.0 - self::SURVIVING) * $killed + self::SURVIVING * $every;
    }
}
