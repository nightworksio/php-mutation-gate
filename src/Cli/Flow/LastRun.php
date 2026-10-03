<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanFile;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * The last run in this checkout: the plan it left in the workspace and its
 * shards' results, judged again as its verdict judged them, reporting
 * nothing and writing no ledger (ADR-0014, decision 12). `plan` and a run
 * in one process both leave their plan there.
 */
final readonly class LastRun
{
    private const string NONE = 'No run has left a plan at %s here: run mutation-gate first.';

    /** Leave a plan where the shards, the verdict and a later `explain` read it. */
    public static function keep(Directory $project, Plan $plan): Written|CannotJudge
    {
        return $project->write(Workspace::plan(), Contents::of(PlanFile::encode($plan)));
    }

    /** The plan a file holds; where there is none, where it would have been. */
    public static function planAt(Directory $project, Path $file): Plan|Missing|CannotJudge
    {
        $contents = $project->read($file);

        return $contents instanceof Contents ? PlanFile::decode($contents->text()) : $contents;
    }

    /** The last run's verdict, or why there is none to read. */
    public static function verdict(Composed $composed): Verdict|CannotJudge
    {
        $plan = self::planAt($composed->adapters->project, Workspace::plan());

        if (! $plan instanceof Plan) {
            return $plan instanceof Missing ? CannotJudge::because(sprintf(self::NONE, $plan->path()->value())) : $plan;
        }

        $results = Results::read($plan, Workspace::results(), $composed->adapters->project);

        return $results instanceof Results
            ? new Judging($composed->adapters, $composed->settings, $composed->setup, $composed->reporting)
                ->again($plan, $results)
            : $results;
    }
}
