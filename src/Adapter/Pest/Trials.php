<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;

use Closure;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Judging;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Selector;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * A run's result, each mutant Pest left uncovered on a line that is not
 * executable judged by its trial (ADR-0004), reading the map of
 * every file a line that reads its value may be in: each step timed. Once
 * the run's time is out, nothing is read and no trial starts: each mutant
 * Pest left uncovered is unjudged, for a later run to judge (ADR-0196).
 *
 * @internal
 */
final readonly class Trials
{
    public function __construct(
        private Project $project,
        private Shell $shell,
        private CapFiles $files,
        private LimitBounds $bounds,
        private Remembered $remembered,
        private bool $perShard = true,
    ) {
    }

    /**
     * @param Covering                       $own  the coverage the run read
     * @param Closure(): (Seconds|Unlimited) $left the time the run has left to start a trial in
     */
    public function of(
        MutationResult $result,
        MutationRequest $request,
        string $results,
        Covering $own,
        CoverageMap|Unshared $shared,
        Laps $laps,
        Closure $left,
    ): MutationResult|CannotJudge {
        $time = $left();

        if ($time instanceof Seconds && $time->seconds() <= 0.0) {
            return $result->withMutants(Mutants::of(...array_map(
                static fn(Mutant $mutant): Mutant => $mutant->status() === MutantStatus::Uncovered
                    ? $mutant->unjudged(OutOfTime::BeforeRunning)
                    : $mutant,
                [...$result->mutants()],
            )));
        }

        $from = $laps->now();
        $whole = new WholeMap($this->project, $this->remembered);
        $reads = $whole->covering($request, $own, $shared);
        $read = $laps->lap(Step::TrialCoverage, $from);
        $from = $laps->now();
        $judged = $reads instanceof CannotJudge
            ? $reads
            : new Judging($this->project, $this->shell, $this->files, $this->bounds)->of(
                $result,
                $request,
                $results,
                fn(): Selector => $whole->selector($request, $reads, $shared, $this->perShard),
                $left,
            );

        return $judged instanceof CannotJudge
            ? $judged
            : $judged->withSteps(StepTimes::of($read, $laps->lap(Step::Trials, $from)));
    }
}
