<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Cost\FirstRunShape;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\Cost\StartUpSamples;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;

/**
 * What a plan measures of the first run of the units it runs, for a cost
 * model to estimate each unit no shard has timed by (ADR-0006):
 * where the runner's mutants of each of their files start, as it made them
 * once as the plan was made (ADR-0004), or else the default set's, the
 * fastest of a few runs of no test, and how many mutants the runner runs
 * at once on this machine. Shard runners are taken to be like the plan's.
 * Where neither counts any file, or a run of no test cannot run, nothing is
 * measured, and every unit is guessed: an estimate decides where
 * a unit runs, never whether a plan can be made.
 *
 * @internal
 */
final readonly class FirstRuns
{
    public function __construct(
        private Adapters $adapters,
        private StartUpSamples $samples,
        private FirstRunShape $shape,
    ) {
    }

    /**
     * What the plan measures of the first run of these units: where each of
     * their files' mutants start, as the runner made them as the plan was
     * made, or else as the gate's own engine makes them.
     */
    public function measured(CoverageMap $map, Units $units, MutantSites $made): FirstRun
    {
        $sites = $this->sites($this->adapters->engine, StartUpTiming::filesOf($map, $units), $made);
        $first = $sites->first();

        return $first instanceof Path ? $this->measuredWith($map, $sites, $first) : FirstRun::unmeasured();
    }

    private function measuredWith(CoverageMap $map, MutantSites $sites, Path $file): FirstRun
    {
        $startUp = new StartUpTiming($this->adapters, $this->samples)->fastest($file);

        return $startUp instanceof CannotJudge
            ? FirstRun::unmeasured()
            : FirstRun::of($map, $sites, $startUp, $this->adapters->processes(), $this->shape);
    }

    /** Where the runner made the mutants of each of these files, or else where the engine makes them. */
    private function sites(Engine|NotGiven $engine, Paths $files, MutantSites $made): MutantSites
    {
        $sites = MutantSites::none();

        foreach ($files as $file) {
            $sites = $sites->and($made->has($file) ? $made->of($file) : $this->siteOf($engine, $file));
        }

        return $sites;
    }

    private function siteOf(Engine|NotGiven $engine, Path $file): MutantSites
    {
        if (! $engine instanceof Engine) {
            return MutantSites::none();
        }

        $code = $this->adapters->project->read($file);
        $counted = $code instanceof Contents ? $engine->sitesOf($file, $code) : $code;

        return $counted instanceof MutantSites ? $counted : MutantSites::none();
    }
}
