<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\Cost\StartUpSamples;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;

/**
 * What a plan measures of the first run of the units it runs, for a cost
 * model to estimate each unit no shard has timed by (ADR-0006, decision 4):
 * where the default set's mutants of each of their files start, the
 * fastest of a few runs of no test, and how many mutants the runner runs
 * at once on this machine. Shard runners are taken to be like the plan's.
 * Where the default set is not registered, or a run of no test cannot run,
 * nothing is measured, and every unit is guessed: an estimate decides where
 * a unit runs, never whether a plan can be made.
 */
final readonly class FirstRuns
{
    public function __construct(private Adapters $adapters, private StartUpSamples $samples)
    {
    }

    public function measured(CoverageMap $map, Units $units): FirstRun
    {
        $engine = $this->adapters->engine;
        $sites = $engine instanceof NotGiven
            ? MutantSites::none()
            : $this->sites($engine, $this->filesOf($map, $units));
        $first = $sites->first();

        return $first instanceof Path ? $this->measuredWith($map, $sites, $first) : FirstRun::unmeasured();
    }

    private function measuredWith(CoverageMap $map, MutantSites $sites, Path $file): FirstRun
    {
        $startUp = $this->startUp($file);

        return $startUp instanceof CannotJudge
            ? FirstRun::unmeasured()
            : FirstRun::of($map, $sites, $startUp, $this->adapters->processes());
    }

    /** The fastest of the runs of no test, each serving this file unchanged, or why one could not run. */
    private function startUp(Path $file): Seconds|CannotJudge
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
    private function filesOf(CoverageMap $map, Units $units): Paths
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

    /** Where the engine makes the mutants of each of these files it can read and parse. */
    private function sites(Engine $engine, Paths $files): MutantSites
    {
        $sites = MutantSites::none();

        foreach ($files as $file) {
            $sites = $sites->and($this->siteOf($engine, $file));
        }

        return $sites;
    }

    private function siteOf(Engine $engine, Path $file): MutantSites
    {
        $code = $this->adapters->project->read($file);
        $counted = $code instanceof Contents ? $engine->sitesOf($file, $code) : $code;

        return $counted instanceof MutantSites ? $counted : MutantSites::none();
    }
}
