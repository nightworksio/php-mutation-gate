<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Buildkite;

use function array_is_list;
use function array_key_exists;
use function array_map;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_string;
use function iterator_to_array;
use function json_decode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\CiPlan;

use function sprintf;

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
    private const string COMMAND = 'command';

    private const string KEY = 'key';

    private const string LABEL = 'label';

    private const string DOWNLOAD = "buildkite-agent artifact download '.mutation-gate/**/*' .";

    private const string PLAN = '.mutation-gate/plan.json';

    /** @param array<string, mixed> $step the step template */
    private function __construct(private Variables $variables, private array $step, private string $to)
    {
    }

    /**
     * A plan that prints its steps to this file, built from this step template.
     *
     * @param array<string, mixed> $step
     */
    public static function printing(string $to, array $step, Variables $variables): self
    {
        return new self($variables, $step, $to);
    }

    /** `{"step": {…}}`, printed to the standard output. */
    public static function fromOptions(Options $options): self|Invalid
    {
        $with = json_decode($options->json(), associative: true);
        $step = is_array($with) && array_key_exists('step', $with) ? $with['step'] : [];

        return is_array($step) && ($step === [] || ! array_is_list($step))
            ? self::printing('php://stdout', self::keyed($step), Variables::of(getenv()))
            : Invalid::because(Problem::at('step', 'The step template is a map of step keys.'));
    }

    public function publish(Plan $plan): Written|CannotJudge
    {
        $steps = [
            ...array_map($this->shardStep(...), iterator_to_array($plan, preserve_keys: false)),
            ['type' => 'wait', 'continue_on_failure' => true],
            $this->commandStep(
                'mutation: verdict',
                'mutation-gate-verdict',
                sprintf('vendor/bin/mutation-gate verdict --plan=%s --results=.mutation-gate/results', self::PLAN),
            ),
        ];

        return file_put_contents($this->to, Json::encode(['steps' => $steps])) === false
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

        return $pullRequest !== '' && $pullRequest !== 'false'
            ? RunOn::pullRequest($pullRequest, $defaultBranch)
            : RunOn::branch($this->variables->valueOf('BUILDKITE_BRANCH'), $defaultBranch);
    }

    /** @return array<string, mixed> */
    private function shardStep(Shard $shard): array
    {
        $id = $shard->id()->number();

        return [
            ...$this->commandStep(
                sprintf('mutation: %s', $shard->label()),
                sprintf('mutation-gate-shard-%d', $id),
                sprintf('vendor/bin/mutation-gate run --plan=%s --shard=%d', self::PLAN, $id),
            ),
            'artifact_paths' => sprintf('.mutation-gate/results/%d.json', $id),
        ];
    }

    /** @return array<string, mixed> */
    private function commandStep(string $label, string $key, string $command): array
    {
        return [
            ...$this->step,
            self::LABEL => $label,
            self::KEY => $key,
            self::COMMAND => [...$this->templateCommands(), self::DOWNLOAD, $command],
        ];
    }

    /** @return list<string> the commands the template runs first */
    private function templateCommands(): array
    {
        $commands = array_key_exists(self::COMMAND, $this->step) ? $this->step[self::COMMAND] : [];
        $read = [];

        foreach (is_array($commands) ? $commands : [$commands] as $command) {
            $read = is_string($command) ? [...$read, $command] : $read;
        }

        return $read;
    }

    /**
     * @param  array<mixed>         $step
     * @return array<string, mixed>
     */
    private static function keyed(array $step): array
    {
        $keyed = [];

        foreach ($step as $key => $value) {
            $keyed[sprintf('%s', $key)] = $value;
        }

        return $keyed;
    }
}
