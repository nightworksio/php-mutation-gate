<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_map;
use function count;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_file;
use function iterator_to_array;
use function json_encode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\CiPlan;

use function preg_match;
use function sprintf;

/**
 * The CI plan `github`: `shards=<JSON array of {id, label}>` appended to the
 * file `$GITHUB_OUTPUT` names, which a matrix reads with
 * `fromJson(needs.plan.outputs.shards)`. An empty array skips the matrix job,
 * and the verdict still runs. A matrix holds at most {@see MOST_JOBS} jobs.
 */
final readonly class GitHubPlan implements CiPlan, Configurable
{
    /** GitHub's limit of jobs in one matrix, which caps `shards.max` here. */
    public const int MOST_JOBS = 256;

    private const string OUTPUT = 'GITHUB_OUTPUT';

    private const string PULL_REQUEST = '#^refs/pull/(\d+)/#';

    private const string BRANCH = '#^refs/heads/(.+)$#';

    private function __construct(private Variables $variables)
    {
    }

    public static function in(Variables $variables): self
    {
        return new self($variables);
    }

    public static function fromOptions(Options $options): self
    {
        return new self(Variables::of(getenv()));
    }

    public function publish(Plan $plan): Written|CannotJudge
    {
        $output = $this->variables->valueOf(self::OUTPUT);

        return match (true) {
            count($plan) > self::MOST_JOBS => CannotJudge::because(sprintf(
                'The plan holds %d shards, and a GitHub matrix runs at most %d jobs. Set shards.max to %d or less.',
                count($plan),
                self::MOST_JOBS,
                self::MOST_JOBS,
            )),
            $output === '' => CannotJudge::because(
                'GITHUB_OUTPUT is not set, so the plan cannot reach the matrix. Run plan in a GitHub Actions step.',
            ),
            default => self::appended($output, sprintf("shards=%s\n", self::matrixOf($plan))),
        };
    }

    public function shard(Plan $plan): ShardId|CannotJudge
    {
        return WhichShard::in($this->variables, $plan);
    }

    public function runOn(): RunOn|CannotTell
    {
        $ref = $this->variables->valueOf('GITHUB_REF');
        $defaultBranch = $this->defaultBranch();

        $pullRequest = $this->variables->valueOf('GITHUB_EVENT_NAME') === 'pull_request';

        if ($pullRequest && preg_match(self::PULL_REQUEST, $ref, $number) === 1) {
            return RunOn::pullRequest($number[1], $defaultBranch);
        }

        return preg_match(self::BRANCH, $ref, $branch) === 1
            ? RunOn::branch($branch[1], $defaultBranch)
            : CannotTell::because(sprintf('GITHUB_REF is "%s", which is neither a branch nor a pull request.', $ref));
    }

    private function defaultBranch(): Scope|CannotTell
    {
        $event = $this->variables->valueOf('GITHUB_EVENT_PATH');
        $payload = is_file($event) ? file_get_contents($event) : '';

        try {
            $event = Node::decode($payload === false ? '' : $payload);

            return RunOn::branchNamed($event->field('repository')->field('default_branch')->text());
        } catch (NotInShape) {
            return CannotTell::because('The event payload does not name the default branch.');
        }
    }

    private static function matrixOf(Plan $plan): string
    {
        return json_encode(array_map(
            static fn(Shard $shard): array => ['id' => $shard->id()->number(), 'label' => $shard->label()],
            iterator_to_array($plan, preserve_keys: false),
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function appended(string $file, string $line): Written|CannotJudge
    {
        return file_put_contents($file, $line, FILE_APPEND) === false
            ? CannotJudge::because(sprintf('%s could not be written.', $file))
            : Written::to($file);
    }
}
