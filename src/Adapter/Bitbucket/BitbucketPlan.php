<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Bitbucket;

use function getenv;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\CiPlan;

/**
 * The CI plan `bitbucket` (ADR-0024 decision 1). Bitbucket's parallel steps
 * are fixed in its pipeline, so the plan step cuts `--shards=<n>` to match
 * them, and the plan is printed as the generic JSON. Each step's shard is
 * `BITBUCKET_PARALLEL_STEP` + 1, and a `BITBUCKET_PARALLEL_STEP_COUNT` that
 * is not the plan's count of shards cannot be judged. Bitbucket does not
 * name the default branch; `ci.defaultBranch` does.
 */
final readonly class BitbucketPlan implements CiPlan, Configurable
{
    private const string NO_DEFAULT = 'Bitbucket does not name the default branch. Set ci.defaultBranch.';

    private function __construct(private CiJob $job)
    {
    }

    /** The plan for this job. */
    public static function in(CiJob $job): self
    {
        return new self($job);
    }

    /** From `definition`, the pipeline file that runs the gate, which `ci.bitbucket.definition` names. */
    public static function fromOptions(Options $options): self|Invalid
    {
        return CiJob::planned($options, Variables::of(getenv()), self::in(...));
    }

    public function publish(Plan $plan): Publication
    {
        return Publication::printed(PlanListing::of($plan));
    }

    /** The pipeline file that runs the gate. */
    public function definitions(): Paths
    {
        return $this->job->definitions();
    }

    /**
     * A pull request where `BITBUCKET_PR_ID` is set; none for a tag, which is
     * no branch the gate writes for; else the branch `BITBUCKET_BRANCH` names.
     */
    public function runOn(): RunOn|CannotTell
    {
        $defaultBranch = CannotTell::because(self::NO_DEFAULT);
        $variables = $this->job->variables();

        return match (true) {
            $variables->has(Variables::BITBUCKET_PR_ID) => RunOn::pullRequest(
                PullRequestNumber::parse($variables->valueOf(Variables::BITBUCKET_PR_ID)),
                $defaultBranch,
            ),
            $variables->has(Variables::BITBUCKET_TAG) => RunOn::detached($defaultBranch),
            default => RunOn::branch($variables->valueOf(Variables::BITBUCKET_BRANCH), $defaultBranch),
        };
    }

    /** The step's OpenID Connect token, which a cloud role may trust. */
    public static function withheld(): Withheld
    {
        return Withheld::of('BITBUCKET_STEP_OIDC_TOKEN');
    }

    /** Bitbucket Pipelines sets `BITBUCKET_BUILD_NUMBER` in every step. */
    public static function marker(): CiMarker
    {
        return CiMarker::setting(Variables::BITBUCKET_BUILD_NUMBER);
    }
}
