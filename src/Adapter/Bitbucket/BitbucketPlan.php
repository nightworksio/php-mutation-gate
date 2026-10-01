<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Bitbucket;

use function file_put_contents;
use function getenv;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\CiPlan;

use function sprintf;

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

    private function __construct(private Variables $variables, private string $to, private Path $definition)
    {
    }

    /** A plan that prints to this file, for a pipeline run from this definition. */
    public static function printing(string $to, Variables $variables, Path $definition): self
    {
        return new self($variables, $to, $definition);
    }

    /** From `definition`, the pipeline file that runs the gate, which `ci.bitbucket.definition` names. */
    public static function fromOptions(Options $options): self|Invalid
    {
        $definition = $options->path(Key::of('definition'));

        return match (true) {
            $definition instanceof Path => self::printing('php://output', Variables::of(getenv()), $definition),
            $definition instanceof Problem => Invalid::because($definition),
            default => Invalid::because(
                Problem::at('definition', 'expected the pipeline that runs the gate, as a path'),
            ),
        };
    }

    public function publish(Plan $plan): Written|CannotJudge
    {
        return file_put_contents($this->to, PlanListing::of($plan)) === false
            ? CannotJudge::because(sprintf('%s could not be written.', $this->to))
            : Written::to($this->to);
    }

    public function shard(Plan $plan): ShardId|CannotJudge
    {
        return WhichShard::in($this->variables, $plan);
    }

    /**
     * A pull request where `BITBUCKET_PR_ID` is set; none for a tag, which is
     * no branch the gate writes for; else the branch `BITBUCKET_BRANCH` names.
     */
    public function runOn(): RunOn|CannotTell
    {
        $defaultBranch = CannotTell::because(self::NO_DEFAULT);

        return match (true) {
            $this->variables->has('BITBUCKET_PR_ID') => RunOn::pullRequest(
                PullRequestNumber::parse($this->variables->valueOf('BITBUCKET_PR_ID')),
                $defaultBranch,
            ),
            $this->variables->has('BITBUCKET_TAG') => RunOn::detached($defaultBranch),
            default => RunOn::branch($this->variables->valueOf('BITBUCKET_BRANCH'), $defaultBranch),
        };
    }

    /** The pipeline file that runs the gate. */
    public function definitions(): Paths
    {
        return Paths::of($this->definition);
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
