<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Bitbucket;

use function file_put_contents;
use function getenv;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Written;
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

    private function __construct(private CiJob $job, private string $to)
    {
    }

    /** A plan that prints to this file, for a pipeline run from this definition. */
    public static function printing(string $to, Variables $variables, Path $definition): self
    {
        return new self(CiJob::of($variables, Paths::of($definition)), $to);
    }

    /** From `definition`, the pipeline file that runs the gate, which `ci.bitbucket.definition` names. */
    public static function fromOptions(Options $options): self|Invalid
    {
        $job = CiJob::definedIn($options, Variables::of(getenv()));

        return $job instanceof CiJob ? new self($job, Written::OUTPUT) : $job;
    }

    public function publish(Plan $plan): Written|CannotJudge
    {
        return Written::attempted($this->to, file_put_contents($this->to, PlanListing::of($plan)));
    }

    public function shard(Plan $plan): ShardId|CannotJudge
    {
        return $this->job->shard($plan);
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

    /** The pipeline file that runs the gate. */
    public function definitions(): Paths
    {
        return $this->job->definitions();
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
