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
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
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

    /** The pipeline GitLab runs where `CI_CONFIG_PATH` names no other. */
    private const string PIPELINE_DEFINITION = '.gitlab-ci.yml';

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

    /** `{"template": "<path>"}`, as `ci.gitlab.template` gives it. */
    public static function fromOptions(Options $options): self|Invalid
    {
        $template = $options->text(Key::of('template'));

        return match (true) {
            $template instanceof Problem => Invalid::because($template),
            $template instanceof NotGiven => Invalid::because(
                Problem::at('template', 'expected the file that defines the hidden .mutation-gate job, got nothing'),
            ),
            default => self::writing(self::PIPELINE, $template, Variables::of(getenv())),
        };
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
            ? file_put_contents($this->pipeline, JsonText::encode($this->pipelineOf($plan, $planJob)))
            : false;

        return $written === false
            ? CannotJudge::because(sprintf('%s could not be written.', $this->pipeline))
            : Written::to($this->pipeline);
    }

    public function shard(Plan $plan): ShardId|CannotJudge
    {
        return WhichShard::in($this->variables, $plan);
    }

    /** A merge request's scope, a branch's, or none for a tag, which is no branch the gate writes for. */
    public function runOn(): RunOn|CannotTell
    {
        $defaultBranch = RunOn::branchNamed($this->variables->valueOf('CI_DEFAULT_BRANCH'));

        return match (true) {
            $this->variables->has('CI_MERGE_REQUEST_IID') => RunOn::pullRequest(
                PullRequestNumber::parse($this->variables->valueOf('CI_MERGE_REQUEST_IID')),
                $defaultBranch,
            ),
            $this->variables->has('CI_COMMIT_TAG') => RunOn::detached($defaultBranch),
            default => RunOn::branch($this->variables->valueOf('CI_COMMIT_REF_NAME'), $defaultBranch),
        };
    }

    /** The pipeline `CI_CONFIG_PATH` names, `.gitlab-ci.yml` by default, and the template its child includes. */
    public function definitions(): Paths
    {
        $pipeline = $this->variables->valueOf('CI_CONFIG_PATH');

        return Paths::of(Path::of($pipeline === '' ? self::PIPELINE_DEFINITION : $pipeline), Path::of($this->template));
    }

    /** The job's token and its signed identity, and the registry's and deploy tokens' passwords. */
    public static function withheld(): Withheld
    {
        return Withheld::of(
            'CI_JOB_TOKEN',
            'CI_JOB_JWT*',
            'CI_REGISTRY_PASSWORD',
            'CI_DEPLOY_PASSWORD',
            'CI_DEPENDENCY_PROXY_PASSWORD',
        );
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
