<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

/**
 * A CI's own way of running a plan: GitHub Actions, GitLab, Buildkite,
 * CircleCI, Azure DevOps, Bitbucket Pipelines or plain JSON. Which shard a
 * job is, `--shard` or the variables WhichShard reads say, on every CI.
 */
interface CiPlan
{
    /** The plan in the CI's own format, such as a matrix or a child pipeline, and where it goes. */
    public function publish(Plan $plan): Publication|CannotJudge;

    /**
     * The CI definitions that run the gate, as paths from the repository's
     * root: the content key reads them as they run, and a change to one
     * reaches everything. None where the CI runs no definition from the
     * repository.
     */
    public function definitions(): Paths;

    /**
     * The run's ref, which is its proof scope, whether it is a pull request,
     * and the default branch, where the CI says. Where it cannot, the gate
     * asks git and `ci.defaultBranch`.
     */
    public function runOn(): RunOn|CannotTell;

    /**
     * The CI's own credentials, which no runner hands the project's tests: a declaration of the plan's class,
     * whatever its options, which its registration repeats.
     */
    public static function withheld(): Withheld;

    /**
     * The variable the plan's CI marks every job with: a declaration of the
     * plan's class, which its registration repeats, so a config that names no
     * plan takes the one the job runs in.
     */
    public static function marker(): CiMarker;
}
