<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitLab;

use function array_map;
use function count;
use function dirname;
use function file_put_contents;
use function getenv;
use function is_dir;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\CiPlan;

use function sprintf;

/**
 * The CI plan `gitlab`. `parallel:matrix` has to be in a pipeline before it
 * starts, so the plan is a child pipeline, `.mutation-gate/pipeline.yml`,
 * written as JSON, which is YAML too. It holds one job with a matrix of
 * `SHARD` over the shards and a verdict job that needs it and runs
 * `when: always`. Both fetch the plan from the job that made it, through
 * `needs: pipeline: $PARENT_PIPELINE_ID`, and both extend the hidden job
 * `.mutation-gate`, defined in the file `ci.gitlab.template` names, which the
 * child pipeline includes.
 */
final readonly class GitLabPlan implements CiPlan, Configurable
{
    /** Where the child pipeline is written. */
    public const string PIPELINE = '.mutation-gate/pipeline.yml';

    /** The file that defines `.mutation-gate`, unless `ci.gitlab.template` names another. */
    public const string TEMPLATE = '.gitlab/mutation-gate.yml';

    private const string SHARD_JOB = 'mutation-gate-shard';

    private const string HIDDEN_JOB = '.mutation-gate';

    private const string EXTENDS = 'extends';

    private const string NEEDS = 'needs';

    private const string SCRIPT = 'script';

    private function __construct(private Variables $variables, private string $template, private string $pipeline)
    {
    }

    /** A plan that writes its child pipeline to this file, including this template. */
    public static function writing(string $pipeline, string $template, Variables $variables): self
    {
        return new self($variables, $template, $pipeline);
    }

    /** `{"template": "<path>"}` */
    public static function fromOptions(Options $options): self|Invalid
    {
        $template = Node::decode($options->json())->field('template');

        try {
            $file = $template->isPresent() ? $template->text() : self::TEMPLATE;

            return self::writing(self::PIPELINE, $file, Variables::of(getenv()));
        } catch (NotInShape) {
            return Invalid::because(Problem::at('template', 'The template is a path, written as text.'));
        }
    }

    public function publish(Plan $plan): Written|CannotJudge
    {
        $planJob = $this->variables->valueOf('CI_JOB_NAME');

        if ($planJob === '') {
            return CannotJudge::because(
                'CI_JOB_NAME is not set, so the child pipeline cannot name the job that planned. Plan in a GitLab job.',
            );
        }

        $directory = dirname($this->pipeline);
        $written = is_dir($directory) || mkdir($directory, recursive: true)
            ? file_put_contents($this->pipeline, Json::encode($this->pipelineOf($plan, $planJob)))
            : false;

        return $written === false
            ? CannotJudge::because(sprintf('%s could not be written.', $this->pipeline))
            : Written::to($this->pipeline);
    }

    public function shard(Plan $plan): ShardId|CannotJudge
    {
        return WhichShard::in($this->variables, $plan);
    }

    public function runOn(): RunOn|CannotTell
    {
        $defaultBranch = RunOn::branchNamed($this->variables->valueOf('CI_DEFAULT_BRANCH'));

        return $this->variables->has('CI_MERGE_REQUEST_IID')
            ? RunOn::pullRequest($this->variables->valueOf('CI_MERGE_REQUEST_IID'), $defaultBranch)
            : RunOn::branch($this->variables->valueOf('CI_COMMIT_REF_NAME'), $defaultBranch);
    }

    /** @return array<string, mixed> */
    private function pipelineOf(Plan $plan, string $planJob): array
    {
        $fromThePlan = ['pipeline' => '$PARENT_PIPELINE_ID', 'job' => $planJob];
        $shards = array_map(
            static fn(Shard $shard): string => sprintf('%d', $shard->id()->number()),
            [...$plan],
        );
        $shardJob = [
            self::EXTENDS => self::HIDDEN_JOB,
            self::NEEDS => [$fromThePlan],
            'parallel' => ['matrix' => [['SHARD' => $shards]]],
            self::SCRIPT => ['vendor/bin/mutation-gate run --plan=.mutation-gate/plan.json'],
            'artifacts' => ['when' => 'always', 'paths' => ['.mutation-gate/results/']],
        ];

        return [
            'include' => [['local' => $this->template]],
            ...count($shards) === 0 ? [] : [self::SHARD_JOB => $shardJob],
            'mutation-gate-verdict' => [
                self::EXTENDS => self::HIDDEN_JOB,
                self::NEEDS => count($shards) === 0 ? [$fromThePlan] : [$fromThePlan, ['job' => self::SHARD_JOB]],
                'when' => 'always',
                self::SCRIPT => [
                    'vendor/bin/mutation-gate verdict --plan=.mutation-gate/plan.json --results=.mutation-gate/results',
                ],
            ],
        ];
    }
}
