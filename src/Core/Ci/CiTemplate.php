<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Series;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * A CI definition `init --ci` renders, by its template's file under the
 * package's `resources/ci/` (ADR-0015 decisions 13 and 14), and where it
 * goes: written to a file of its own, or printed to be added to one the CI
 * already reads.
 */
enum CiTemplate: string
{
    case GitHubSingle = 'github/single.yml';

    case GitHubSharded = 'github/sharded.yml';

    case GitLabTemplate = 'gitlab/template.yml';

    case GitLabJobs = 'gitlab/jobs.yml';

    case BuildkitePipeline = 'buildkite/pipeline.yml';

    case BuildkiteUpload = 'buildkite/upload.yml';

    case CircleCi = 'circleci/config.yml';

    case AzureJobs = 'azure/jobs.yml';

    case AzureInclude = 'azure/include.yml';

    case BitbucketPipelines = 'bitbucket/pipelines.yml';

    case JenkinsPipeline = 'jenkins/Jenkinsfile';

    /** The file of the workflow GitHub runs the gate in, among GitHub's workflows. */
    private const string GITHUB_WORKFLOW = 'mutation.yml';

    /** The file of the gate's jobs, which the CI's own definition pulls in, in the directory `init` writes it to. */
    private const string GATE_JOBS = 'mutation-gate.yml';

    /** The directory the gate's GitLab template is in by default: the gate's choice, beside `.gitlab-ci.yml`. */
    private const string GITLAB_TEMPLATES = '.gitlab/';

    /**
     * What holds the store's keys where a CI holds them by a name, restricted to the default branch: GitHub's
     * environment, Azure DevOps' variable group, CircleCI's context, Buildkite's secrets, Bitbucket's deployment
     * environment and Jenkins' credentials.
     */
    private const string KEY_HOLDER = 'mutation-gate-store';

    /** The directory `init --ci=azure` writes the gate's template to: the gate's choice, not Azure's convention. */
    private const string AZURE_TEMPLATES = '.azure/';

    private const string NO_TEMPLATE = 'init --ci writes a definition for %s, not for %s.';

    /**
     * The definitions `init --ci` renders for a CI, in the order it says them; none for plain JSON.
     *
     * @return Listed<self>
     */
    public static function for(BuiltinCiPlan $plan, GitHubWorkflow $workflow): Listed
    {
        return match ($plan) {
            BuiltinCiPlan::GitHub => Listed::of(
                $workflow === GitHubWorkflow::Single ? self::GitHubSingle : self::GitHubSharded,
            ),
            BuiltinCiPlan::GitLab => Listed::of(self::GitLabTemplate, self::GitLabJobs),
            BuiltinCiPlan::Buildkite => Listed::of(self::BuildkitePipeline, self::BuildkiteUpload),
            BuiltinCiPlan::CircleCi => Listed::of(self::CircleCi),
            BuiltinCiPlan::Azure => Listed::of(self::AzureJobs, self::AzureInclude),
            BuiltinCiPlan::Bitbucket => Listed::of(self::BitbucketPipelines),
            BuiltinCiPlan::Jenkins => Listed::of(self::JenkinsPipeline),
            BuiltinCiPlan::Json => Listed::of(),
        };
    }

    /**
     * The file `init --ci` writes the gate's jobs to, which the lines it prints for the CI's own definition pull
     * in: GitLab's template, Buildkite's pipeline or Azure's template; none where the CI's definition is one file.
     */
    public static function included(BuiltinCiPlan $plan, Ci $ci): Path|NotGiven
    {
        return match ($plan) {
            BuiltinCiPlan::GitLab => $ci->gitlabTemplate(),
            BuiltinCiPlan::Buildkite => self::buildkitePipeline(),
            BuiltinCiPlan::Azure => self::azureJobs(),
            BuiltinCiPlan::GitHub,
            BuiltinCiPlan::CircleCi,
            BuiltinCiPlan::Bitbucket,
            BuiltinCiPlan::Jenkins,
            BuiltinCiPlan::Json => NotGiven::value(),
        };
    }

    /** Why `init --ci` writes no definition for a CI of this name, naming each it writes one for. */
    public static function none(string $ci): CannotJudge
    {
        $written = [];

        foreach (BuiltinCiPlan::cases() as $plan) {
            $written = self::for($plan, GitHubWorkflow::Single)->count() === 0 ? $written : [...$written, $plan->value];
        }

        return CannotJudge::because(sprintf(self::NO_TEMPLATE, Series::and(...$written), $ci));
    }

    /** The template GitLab's jobs extend where `ci.gitlab.template` names none, which `init --ci=gitlab` writes. */
    public static function gitlabTemplate(): Path
    {
        return Path::of(self::GITLAB_TEMPLATES)->child(Path::of(self::GATE_JOBS));
    }

    /** The pipeline `init --ci=buildkite` writes, which the step it prints uploads and the config names. */
    public static function buildkitePipeline(): Path
    {
        return Path::of(Definitions::BUILDKITE)->child(Path::of(self::GATE_JOBS));
    }

    /** The template `init --ci=azure` writes, which the line it prints includes and the config names. */
    public static function azureJobs(): Path
    {
        return Path::of(self::AZURE_TEMPLATES)->child(Path::of(self::GATE_JOBS));
    }

    /**
     * What holds the proof store's keys for the verdicts that write the ledger (ADR-0024 decision 4): on Bitbucket,
     * the deployment environment they deploy to; on Jenkins, the credentials they bind.
     */
    public static function keyHolder(): string
    {
        return self::KEY_HOLDER;
    }

    /** Where it goes: a file, from the project, or printed for a file the CI already reads. */
    public function destination(Ci $ci): Path|Printed
    {
        return match ($this) {
            self::GitHubSingle, self::GitHubSharded => Path::of(Definitions::GITHUB)->child(
                Path::of(self::GITHUB_WORKFLOW),
            ),
            self::GitLabTemplate => $ci->gitlabTemplate(),
            self::GitLabJobs => Printed::into(Definitions::GITLAB),
            self::BuildkitePipeline => self::buildkitePipeline(),
            self::BuildkiteUpload => Printed::into('the pipeline Buildkite runs'),
            self::CircleCi => Printed::into(Definitions::CIRCLECI),
            self::AzureJobs => self::azureJobs(),
            self::AzureInclude => Printed::into(Definitions::AZURE),
            self::BitbucketPipelines => Printed::into(Definitions::BITBUCKET),
            self::JenkinsPipeline => Printed::into($ci->jenkinsDefinition()->value()),
        };
    }
}
