<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Buildkite;

use function array_map;
use function getenv;
use function implode;
use function is_string;
use function mb_strtoupper;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\BuildkiteStep;
use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\CiTemplate;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
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
use NightWorksIO\MutationGate\Core\Proof\StoreVariable;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
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

    private const string VERDICT = 'mutation: verdict';

    /** What starts a build the store's keys are fetched for: a push, a schedule, the API or a person. */
    private const array TRUSTED_SOURCES = ['webhook', 'schedule', 'api', 'ui'];

    /**
     * A build of the default branch, started by one of the sources, that is neither a pull request nor a tag: a tag
     * build's `build.branch` is the tag's name, which anyone who can push a tag can make the default branch's.
     */
    private const string TRUSTED = <<<'IF'
        (%s) && build.branch == pipeline.default_branch && build.pull_request.id == null && build.tag == null
        IF;

    /** How a command sets a variable from the cluster secret that holds it, `$` written `$$` for the upload. */
    private const string FETCHED = 'export %s="$$(buildkite-agent secret get %s)"';


    /** @param list<string> $commands the commands the step template runs before the gate's */
    private function __construct(private CiJob $job, private BuildkiteStep $step, private array $commands)
    {
    }

    /** The plan for a job with these variables, whose steps are built from this step template. */
    public static function of(BuildkiteStep $step, Variables $variables): self
    {
        $commands = Options::of($step->json());
        $one = $commands->text(Key::of(BuildkiteStep::COMMAND));
        $many = $commands->texts(Key::of(BuildkiteStep::COMMAND));

        return new self(
            CiJob::of($variables, Paths::of(Ci::none()->buildkiteDefinition())),
            $step,
            match (true) {
                is_string($one) => [$one],
                $many instanceof Listed => [...$many],
                default => [],
            },
        );
    }

    /** This plan, run by the pipeline at this path rather than `.buildkite/pipeline.yml`. */
    public function definedIn(Path $definition): self
    {
        return new self(CiJob::of($this->job->variables(), Paths::of($definition)), $this->step, $this->commands);
    }

    /** `{"step": {…}, "definition": "<path>"}`, printed to the output. */
    public static function fromOptions(Options $options): self|Invalid
    {
        $step = $options->object(Key::of('step'));
        $definition = $options->text(Key::of(CiJob::DEFINITION));

        return match (true) {
            $step instanceof Problem => Invalid::because(
                Problem::at('step', 'The step template is a map of step keys.'),
            ),
            $step instanceof Options && ! self::commandsIn($step) => Invalid::because(
                Problem::at('step.command', 'expected a command, or a list of commands, as text'),
            ),
            $definition instanceof Problem => Invalid::because($definition),
            $definition instanceof NotGiven, $definition === '' => Invalid::because(
                Problem::at(CiJob::DEFINITION, CiJob::UNDEFINED),
            ),
            default => self::of(
                $step instanceof Options ? BuildkiteStep::of($step->written()) : BuildkiteStep::none(),
                Variables::of(getenv()),
            )->definedIn(Path::of($definition)),
        };
    }

    /** The steps, printed for `buildkite-agent pipeline upload` to read from a pipe. */
    public function publish(Plan $plan): Publication
    {
        $verdict = sprintf('vendor/bin/mutation-gate verdict --plan=%s --results=.mutation-gate/results', self::PLAN);
        $sources = array_map(
            static fn(string $source): string => sprintf('build.source == "%s"', $source),
            self::TRUSTED_SOURCES,
        );
        $trusted = sprintf(self::TRUSTED, implode(' || ', $sources));
        $steps = [
            ...array_map($this->shardStep(...), [...$plan]),
            Json::object(Member::of('type', 'wait'), Member::of('continue_on_failure', value: true)),
            $this->commandStep(self::VERDICT, 'mutation-gate-verdict-store', ...[...$this->fetched(), $verdict])
                ->with(Member::of('if', $trusted)),
            $this->commandStep(self::VERDICT, 'mutation-gate-verdict', $verdict)
                ->with(Member::of('if', sprintf('!(%s)', $trusted))),
        ];

        return Publication::printed(Json::object(Member::of('steps', Json::items(...$steps)))->printed());
    }

    /** The pipeline Buildkite uploads from the repository. */
    public function definitions(): Paths
    {
        return $this->job->definitions();
    }

    public function runOn(): RunOn|CannotTell
    {
        $variables = $this->job->variables();
        $defaultBranch = RunOn::branchNamed($variables->valueOf('BUILDKITE_PIPELINE_DEFAULT_BRANCH'));
        $pullRequest = $variables->valueOf('BUILDKITE_PULL_REQUEST');

        return match (true) {
            $pullRequest !== '' && $pullRequest !== 'false'
                => RunOn::pullRequest(PullRequestNumber::parse($pullRequest), $defaultBranch),
            $variables->valueOf('BUILDKITE_TAG') !== '' => RunOn::detached($defaultBranch),
            default => RunOn::branch($variables->valueOf('BUILDKITE_BRANCH'), $defaultBranch),
        };
    }

    /** Buildkite sets `BUILDKITE` to `true` in every job. */
    public static function marker(): CiMarker
    {
        return CiMarker::saying(Variables::BUILDKITE);
    }

    /**
     * The agent's token, which can upload and change pipelines, and the job
     * API's token and socket, which can change the job's environment.
     */
    public static function withheld(): Withheld
    {
        return Withheld::of(
            'BUILDKITE_AGENT_ACCESS_TOKEN',
            'BUILDKITE_AGENT_TOKEN',
            'BUILDKITE_AGENT_JOB_API_TOKEN',
            'BUILDKITE_AGENT_JOB_API_SOCKET',
        );
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

    private function commandStep(string $label, string $key, string ...$commands): Json
    {
        return $this->step->json()->with(
            Member::of(self::LABEL, $label),
            Member::of(self::KEY, $key),
            Member::of(BuildkiteStep::COMMAND, Json::items(...$this->commands, ...[self::DOWNLOAD, ...$commands])),
        );
    }

    /**
     * The commands that set the S3 store's keys from the cluster secrets that
     * hold them, each named for the key holder: `AWS_ACCESS_KEY_ID` from
     * `MUTATION_GATE_STORE_AWS_ACCESS_KEY_ID`.
     *
     * @return list<string>
     */
    private function fetched(): array
    {
        $holder = mb_strtoupper(str_replace('-', '_', CiTemplate::keyHolder()));
        $fetched = [];

        foreach ([StoreVariable::AwsAccessKey, StoreVariable::AwsSecretKey] as $variable) {
            $fetched[] = sprintf(self::FETCHED, $variable->value, sprintf('%s_%s', $holder, $variable->value));
        }

        return $fetched;
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
