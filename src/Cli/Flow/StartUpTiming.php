<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\StartUpSamples;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * How long a mutant's own run takes to start on this machine: the fastest of
 * a few runs of no test, each started as the runner starts a mutant's run
 * of a file, its mutant the file unchanged (ADR-0006, decision 4). A plan
 * weighs units by it; a run lays each mutant's limit on it (ADR-0008,
 * decision 2), measuring once, before its first mutant, on the machine its
 * mutants run on.
 */
final readonly class StartUpTiming
{
    public function __construct(private Adapters $adapters, private StartUpSamples $samples)
    {
    }

    /**
     * The start-up of a run of these units, served the first file they hold:
     * a unit's own file, or the first file of the map a held unit holds;
     * unmeasured where they hold none, or a run of no test cannot run, so
     * each limit keeps the start-up the rule gives where nothing measured one.
     */
    public function of(CoverageMap $map, Units $units): Seconds|Unmeasured
    {
        $file = $this->firstFileOf($map, $units);
        $fastest = $file instanceof Path ? $this->fastest($file) : Unmeasured::duration();

        return $fastest instanceof Seconds ? $fastest : Unmeasured::duration();
    }

    /** The fastest of the runs of no test, each serving this file unchanged, or why one could not run. */
    public function fastest(Path $file): Seconds|CannotJudge
    {
        $fastest = $this->adapters->runner->startUp($file, $this->adapters->withheld);

        for ($run = 1; $run < $this->samples->runs() && $fastest instanceof Seconds; $run++) {
            $again = $this->adapters->runner->startUp($file, $this->adapters->withheld);
            $faster = $again instanceof Seconds && $again->microseconds() < $fastest->microseconds();
            $fastest = $faster || $again instanceof CannotJudge ? $again : $fastest;
        }

        return $fastest;
    }

    /** Each unit's own path, and every file of the map a held unit's path holds. */
    public static function filesOf(CoverageMap $map, Units $units): Paths
    {
        $files = [];

        foreach ($units as $unit) {
            $files[] = $unit->path();

            foreach ($unit->isHeld() ? $map->files() : [] as $file) {
                if ($file->within($unit->path())) {
                    $files[] = $file;
                }
            }
        }

        return Paths::of(...$files);
    }

    /** The first file these units hold: a unit's own path, or the first file of the map a held unit holds. */
    private function firstFileOf(CoverageMap $map, Units $units): Path|NotGiven
    {
        foreach ($units as $unit) {
            foreach ($unit->isHeld() ? self::filesOf($map, Units::of($unit)) : [$unit->path()] as $file) {
                if (! $file->equals($unit->path()) || ! $unit->isHeld()) {
                    return $file;
                }
            }
        }

        return NotGiven::value();
    }
}
