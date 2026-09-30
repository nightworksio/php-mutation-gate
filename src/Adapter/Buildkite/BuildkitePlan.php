<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Buildkite;

use function array_map;
use function file_put_contents;
use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\BuildkiteStep;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\CiPlan;

use function sprintf;
use function str_replace;

/**
 * The CI plan `buildkite`: steps for `buildkite-agent pipeline upload`,
 * printed as JSON. One command step per shard, a wait that continues on
 * failure, then the verdict. Every command step is built from the step
 * template `ci.buildkite.step`: its keys, such as agents, plugins and env, are
 * in the step, and its commands run before the gate's. The plan and the
 * results travel with `buildkite-agent artifact`.
 */
final readonly class BuildkitePlan implements CiPlan, Configurable
{
    private const string KEY = 'key';

    private const string LABEL = 'label';

    private const string DOWNLOAD = "buildkite-agent artifact download '.mutation-gate/**/*' .";

    private const string PLAN = '.mutation-gate/plan.json';


    /** @param list<string> $commands the commands the step template runs before the gate's */
    private function __construct(
        private Variables $variables,
        private BuildkiteStep $step,
        private array $commands,
        private string $to,
        private Path $definition,
    ) {
    }

    /** A plan that prints its steps to this file, built from this step template. */
    public static function printing(string $to, BuildkiteStep $step, Variables $variables): self
    {
        $commands = Options::of($step->json());
        $one = $commands->text(Key::of(BuildkiteStep::COMMAND));
        $many = $commands->texts(Key::of(BuildkiteStep::COMMAND));

        return new self(
            $variables,
            $step,
            match (true) {
                is_string($one) => [$one],
                $many instanceof Listed => [...$many],
                default => [],
            },
            $to,
            Ci::none()->buildkiteDefinition(),
        );
    }

    /** This plan, run by the pipeline at this path rather than `.buildkite/pipeline.yml`. */
    public function definedIn(Path $definition): self
    {
        return new self($this->variables, $this->step, $this->commands, $this->to, $definition);
    }

    /** `{"step": {…}, "definition": "<path>"}`, printed to the output. */
    public static function fromOptions(Options $options): self|Invalid
    {
        $step = $options->object(Key::of('step'));
        $definition = $options->text(Key::of('definition'));

        return match (true) {
            $step instanceof Problem => Invalid::because(
                Problem::at('step', 'The step template is a map of step keys.'),
            ),
            $step instanceof Options && ! self::commandsIn($step) => Invalid::because(
                Problem::at('step.command', 'expected a command, or a list of commands, as text'),
            ),
            $definition instanceof Problem => Invalid::because($definition),
            $definition instanceof NotGiven, $definition === '' => Invalid::because(
                Problem::at('definition', 'expected the pipeline that runs the gate, as a path'),
            ),
            default => self::printing(
                'php://output',
                $step instanceof Options ? BuildkiteStep::of($step->written()) : BuildkiteStep::none(),
                Variables::of(getenv()),
            )->definedIn(Path::of($definition)),
        };
    }

    public function publish(Plan $plan): Written|CannotJudge
    {
        $steps = [
            ...array_map($this->shardStep(...), [...$plan]),
            Json::object(Member::of('type', 'wait'), Member::of('continue_on_failure', value: true)),
            $this->commandStep(
                'mutation: verdict',
                'mutation-gate-verdict',
                sprintf('vendor/bin/mutation-gate verdict --plan=%s --results=.mutation-gate/results', self::PLAN),
            ),
        ];

        return file_put_contents(
            $this->to,
            Json::object(Member::of('steps', Json::items(...$steps)))->pretty(),
        ) === false
            ? CannotJudge::because(sprintf('%s could not be written.', $this->to))
            : Written::to($this->to);
    }

    public function shard(Plan $plan): ShardId|CannotJudge
    {
        return WhichShard::in($this->variables, $plan);
    }

    public function runOn(): RunOn|CannotTell
    {
        $defaultBranch = RunOn::branchNamed($this->variables->valueOf('BUILDKITE_PIPELINE_DEFAULT_BRANCH'));
        $pullRequest = $this->variables->valueOf('BUILDKITE_PULL_REQUEST');

        return match (true) {
            $pullRequest !== '' && $pullRequest !== 'false'
                => RunOn::pullRequest(PullRequestNumber::parse($pullRequest), $defaultBranch),
            $this->variables->valueOf('BUILDKITE_TAG') !== '' => RunOn::detached($defaultBranch),
            default => RunOn::branch($this->variables->valueOf('BUILDKITE_BRANCH'), $defaultBranch),
        };
    }

    /** The pipeline Buildkite uploads from the repository to run the gate. */
    public function definitions(): Paths
    {
        return Paths::of($this->definition);
    }

    /** The agent's token, which can upload and change pipelines. */
    public static function withheld(): Withheld
    {
        return Withheld::of('BUILDKITE_AGENT_ACCESS_TOKEN', 'BUILDKITE_AGENT_TOKEN');
    }

    /** Whether a step template's `command` is left out, a command or a list of them. */
    private static function commandsIn(Options $step): bool
    {
        $one = $step->text(Key::of(BuildkiteStep::COMMAND));

        return ! $one instanceof Problem || ! $step->texts(Key::of(BuildkiteStep::COMMAND)) instanceof Problem;
    }

    private function shardStep(Shard $shard): Json
    {
        $id = $shard->id()->number();

        return $this->commandStep(
            sprintf('mutation: %s', $this->literal($shard->label())),
            sprintf('mutation-gate-shard-%d', $id),
            sprintf('vendor/bin/mutation-gate run --plan=%s --shard=%d', self::PLAN, $id),
        )->with(Member::of('artifact_paths', sprintf('.mutation-gate/results/%d.json', $id)));
    }

    private function commandStep(string $label, string $key, string $command): Json
    {
        return $this->step->json()->with(
            Member::of(self::LABEL, $label),
            Member::of(self::KEY, $key),
            Member::of(BuildkiteStep::COMMAND, Json::items(...$this->commands, ...[self::DOWNLOAD, $command])),
        );
    }

    /**
     * Text as the agent shows it, not as it expands it: `pipeline upload`
     * reads `$NAME` as a variable, and a label names paths a pull request
     * chooses, so each `$` is written `$$`.
     */
    private function literal(string $text): string
    {
        return str_replace('$', '$$', $text);
    }
}
