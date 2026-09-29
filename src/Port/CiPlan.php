<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Written;

/** A CI's own way of running a plan: GitHub Actions, GitLab, Buildkite, CircleCI or plain JSON. */
interface CiPlan
{
    /** Hand the plan to the CI in its own format, such as a matrix or a child pipeline. */
    public function publish(Plan $plan): Written|CannotJudge;

    /** Which of the plan's shards this job runs, where no `--shard` names it. */
    public function shard(Plan $plan): ShardId|CannotJudge;

    /**
     * The run's ref, which is its proof scope, whether it is a pull request,
     * and the default branch, where the CI says. Where it cannot, the gate
     * asks git and `ci.defaultBranch`.
     */
    public function runOn(): RunOn|CannotTell;
}
