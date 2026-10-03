<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Azure;

use function count;
use function getenv;
use function json_encode;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\CiPlan;

use function sprintf;

/**
 * The CI plan `azure` (ADR-0024 decision 1). The plan job sets the output
 * variable `matrix` to the matrix the `mutation` job's `strategy: matrix`
 * reads, one leg per shard, `s1`, `s2`, …, each setting `SHARD`. Azure makes
 * at least one job, so a plan with no shards is one leg, `none`, whose
 * `SHARD` is empty and which runs nothing. Azure DevOps does not name the
 * default branch; `ci.defaultBranch` does.
 */
final readonly class AzurePlan implements CiPlan, Configurable
{
    /** The output variable the matrix is set in, which the template's `mutation` job reads. */
    public const string OUTPUT = 'matrix';

    /** How Azure's logging command sets a job's output variable. */
    private const string SET_OUTPUT = "##vso[task.setvariable variable=%s;isOutput=true]%s\n";

    private const string NO_DEFAULT = 'Azure DevOps does not name the default branch. Set ci.defaultBranch.';

    /** A GitHub pull request's number, which Azure sets where it differs from the pull request's id. */
    private const string PULL_REQUEST_NUMBER = 'SYSTEM_PULLREQUEST_PULLREQUESTNUMBER';

    private function __construct(private CiJob $job)
    {
    }

    /** The plan for this job. */
    public static function in(CiJob $job): self
    {
        return new self($job);
    }

    /** From `definition`, the pipeline file that runs the gate, which `ci.azure.definition` names. */
    public static function fromOptions(Options $options): self|Invalid
    {
        return CiJob::planned($options, Variables::of(getenv()), self::in(...));
    }

    /** The logging command that sets the output variable `matrix`, printed where Azure reads it. */
    public function publish(Plan $plan): Publication
    {
        return Publication::printed(sprintf(self::SET_OUTPUT, self::OUTPUT, $this->matrixOf($plan)));
    }

    /** The pipeline file that runs the gate. */
    public function definitions(): Paths
    {
        return $this->job->definitions();
    }

    /**
     * A pull request where `BUILD_REASON` is `PullRequest`, by its number: `SYSTEM_PULLREQUEST_PULLREQUESTNUMBER`,
     * which Azure sets for GitHub's pull requests, whose id is not their number, and otherwise
     * `SYSTEM_PULLREQUEST_PULLREQUESTID`. Else the ref `BUILD_SOURCEBRANCH` names, which is a branch's scope, or
     * none for a tag.
     */
    public function runOn(): RunOn|CannotTell
    {
        $defaultBranch = CannotTell::because(self::NO_DEFAULT);
        $variables = $this->job->variables();
        $number = $variables->has(self::PULL_REQUEST_NUMBER)
            ? $variables->valueOf(self::PULL_REQUEST_NUMBER)
            : $variables->valueOf('SYSTEM_PULLREQUEST_PULLREQUESTID');

        return $variables->valueOf('BUILD_REASON') === 'PullRequest'
            ? RunOn::pullRequest(PullRequestNumber::parse($number), $defaultBranch)
            : RunOn::onRef($variables->valueOf('BUILD_SOURCEBRANCH'), $defaultBranch);
    }

    /**
     * The job's access token, which can act on the project with the build's identity, and the personal access
     * token the `az devops` command line reads.
     */
    public static function withheld(): Withheld
    {
        return Withheld::of('SYSTEM_ACCESSTOKEN', 'AZURE_DEVOPS_EXT_PAT');
    }

    /** Azure Pipelines sets `TF_BUILD` in every job, to `True`. */
    public static function marker(): CiMarker
    {
        return CiMarker::setting(Variables::TF_BUILD);
    }

    /** `{"s1": {"SHARD": "1"}, …}`, or one leg that runs nothing where the plan holds no shard. */
    private function matrixOf(Plan $plan): string
    {
        $matrix = count($plan) === 0 ? ['none' => [WhichShard::VARIABLE => '']] : [];

        foreach ($plan as $shard) {
            $number = $shard->id()->number();
            $matrix[sprintf('s%d', $number)] = [WhichShard::VARIABLE => sprintf('%d', $number)];
        }

        return json_encode($matrix, JsonText::FLAGS);
    }
}
