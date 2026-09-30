<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Azure;

use function count;
use function file_put_contents;
use function getenv;
use function json_encode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
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
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Written;
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
    /** The output variable the matrix is set in, as the template reads it. */
    private const string OUTPUT = 'matrix';

    /** How Azure's logging command sets a job's output variable. */
    private const string SET_OUTPUT = "##vso[task.setvariable variable=%s;isOutput=true]%s\n";

    private const string NO_DEFAULT = 'Azure DevOps does not name the default branch. Set ci.defaultBranch.';

    private function __construct(private Variables $variables, private string $to, private Path $definition)
    {
    }

    /** A plan that prints to this file, for a pipeline run from this definition. */
    public static function printing(string $to, Variables $variables, Path $definition): self
    {
        return new self($variables, $to, $definition);
    }

    /** From `definition`, the pipeline file that runs the gate, which `ci.azure.definition` names. */
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
        $line = sprintf(self::SET_OUTPUT, self::OUTPUT, $this->matrixOf($plan));

        return file_put_contents($this->to, $line) === false
            ? CannotJudge::because(sprintf('%s could not be written.', $this->to))
            : Written::to($this->to);
    }

    public function shard(Plan $plan): ShardId|CannotJudge
    {
        return WhichShard::in($this->variables, $plan);
    }

    /**
     * A pull request where `BUILD_REASON` is `PullRequest`, by
     * `SYSTEM_PULLREQUEST_PULLREQUESTID`; else the ref `BUILD_SOURCEBRANCH`
     * names, which is a branch's scope, or none for a tag.
     */
    public function runOn(): RunOn|CannotTell
    {
        $defaultBranch = CannotTell::because(self::NO_DEFAULT);

        return $this->variables->valueOf('BUILD_REASON') === 'PullRequest'
            ? RunOn::pullRequest(
                PullRequestNumber::parse($this->variables->valueOf('SYSTEM_PULLREQUEST_PULLREQUESTID')),
                $defaultBranch,
            )
            : RunOn::onRef($this->variables->valueOf('BUILD_SOURCEBRANCH'), $defaultBranch);
    }

    /** The pipeline file that runs the gate. */
    public function definitions(): Paths
    {
        return Paths::of($this->definition);
    }

    /** The job's access token, which can act on the project with the build's identity. */
    public static function withheld(): Withheld
    {
        return Withheld::of('SYSTEM_ACCESSTOKEN');
    }

    /** Azure Pipelines sets `TF_BUILD` in every job, to `True`. */
    public static function marker(): CiMarker
    {
        return CiMarker::setting(Variables::TF_BUILD);
    }

    /** `{"s1": {"SHARD": "1"}, …}`, or one leg that runs nothing where the plan holds no shard. */
    private function matrixOf(Plan $plan): string
    {
        $matrix = count($plan) === 0 ? ['none' => ['SHARD' => '']] : [];

        foreach ($plan as $shard) {
            $number = $shard->id()->number();
            $matrix[sprintf('s%d', $number)] = ['SHARD' => sprintf('%d', $number)];
        }

        return json_encode($matrix, JsonText::FLAGS);
    }
}
