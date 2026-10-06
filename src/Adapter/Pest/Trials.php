<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Judging;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

/**
 * A run's result, each mutant Pest left uncovered on a line that is not
 * executable judged by its trial (ADR-0004, decision 8), reading the map of
 * every file a line that reads its value may be in: each step timed.
 */
final readonly class Trials
{
    public function __construct(
        private Project $project,
        private Shell $shell,
        private CapFiles $files,
        private LimitBounds $bounds,
        private Remembered $remembered,
    ) {
    }

    /** @param Covering $own the coverage the run read */
    public function of(
        MutationResult $result,
        MutationRequest $request,
        string $results,
        Covering $own,
        CoverageMap|Unshared $shared,
        Laps $laps,
    ): MutationResult|CannotJudge {
        $from = $laps->now();
        $reads = new WholeMap($this->project, $this->remembered)->covering($request, $own, $shared);
        $read = $laps->lap(Step::TrialCoverage, $from);
        $from = $laps->now();
        $judged = $reads instanceof CannotJudge
            ? $reads
            : new Judging($this->project, $this->shell, $this->files, $this->bounds)
                ->of($result, $request, $results, $reads);

        return $judged instanceof CannotJudge
            ? $judged
            : $judged->withSteps(StepTimes::of($read, $laps->lap(Step::Trials, $from)));
    }
}
