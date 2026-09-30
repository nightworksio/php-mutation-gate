<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Path;

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

    /** The file of the workflow GitHub runs the gate in, among GitHub's workflows. */
    private const string GITHUB_WORKFLOW = 'mutation.yml';

    /** The file of the pipeline Buildkite uploads to run the gate, among Buildkite's pipelines. */
    private const string BUILDKITE_PIPELINE = 'mutation-gate.yml';

    private const string NO_TEMPLATE
        = 'init --ci writes a definition for github, gitlab, buildkite or circleci, not %s.';

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
            BuiltinCiPlan::Json => Listed::of(),
        };
    }

    /** Why `init --ci` writes no definition for a CI of this name. */
    public static function none(string $ci): CannotJudge
    {
        return CannotJudge::because(sprintf(self::NO_TEMPLATE, $ci));
    }

    /** The pipeline `init --ci=buildkite` writes, which the step it prints uploads and the config names. */
    public static function buildkitePipeline(): Path
    {
        return Path::of(Definitions::BUILDKITE)->child(Path::of(self::BUILDKITE_PIPELINE));
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
        };
    }
}
