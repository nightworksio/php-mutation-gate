<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function count;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function in_array;
use function is_file;
use function json_encode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
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

    /** How `GITHUB_WORKFLOW_REF` spells the workflow: owner, repository, then its path before the ref. */
    public const string WORKFLOW = '~^[^/]+/[^/]+/(?<path>[^@]+)@~';

    private const string OUTPUT = 'GITHUB_OUTPUT';

    /** One line of JSON, as `$GITHUB_OUTPUT` takes a value. */
    private const int JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;

    private const string PULL_REQUEST = '#^refs/pull/(\d+)/#';

    private const string BRANCH = '#^refs/heads/(.+)$#';

    private const string EVENT = 'GITHUB_EVENT_NAME';

    private const string NO_PULL_REQUEST = 'This run is not for a pull request.';

    /** The events whose ref is a branch the run may write for: none runs code from a pull request. */
    private const array TRUSTED = ['push', 'schedule', 'workflow_dispatch'];

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
            default => $this->appended($output, sprintf("shards=%s\n", $this->matrixOf($plan))),
        };
    }

    public function shard(Plan $plan): ShardId|CannotJudge
    {
        return WhichShard::in($this->variables, $plan);
    }

    /**
     * A pull request's scope wherever the event payload or a `pull_request`
     * ref names one; a branch only on `push`, `schedule` and
     * `workflow_dispatch`; and, for a tag or any other event, such as
     * `workflow_run` or `issue_comment`, which act on code from elsewhere, no
     * scope at all, so the run writes nothing. The run is for `GITHUB_SHA`.
     */
    public function runOn(): RunOn|CannotTell
    {
        $payload = $this->payload();
        $run = $this->runIn($payload, $this->defaultBranchIn($payload));
        $commit = $this->variables->valueOf('GITHUB_SHA');

        return $run instanceof RunOn && $commit !== '' ? $run->withCommit(Revision::ref($commit)) : $run;
    }

    /** The workflow `GITHUB_WORKFLOW_REF` names. */
    public function definitions(): Paths
    {
        return preg_match(self::WORKFLOW, $this->variables->valueOf('GITHUB_WORKFLOW_REF'), $found) === 1
            ? Paths::of(Path::of($found['path']))
            : Paths::none();
    }

    /** The Actions runtime's token and variables, and the workflow's `GITHUB_TOKEN`. */
    public function withheld(): Withheld
    {
        return Withheld::of('ACTIONS_*', 'GITHUB_TOKEN');
    }

    private function runIn(Node|CannotTell $payload, Scope|CannotTell $defaultBranch): RunOn|CannotTell
    {
        $number = $this->pullRequestIn($payload);
        $branch = $this->branch();
        $trusted = in_array($this->variables->valueOf(self::EVENT), self::TRUSTED, strict: true);

        return match (true) {
            $number instanceof PullRequestNumber => RunOn::pullRequest($number, $defaultBranch),
            $trusted && $branch !== '' => RunOn::branch($branch, $defaultBranch),
            default => RunOn::detached($defaultBranch),
        };
    }

    /** The pull request's number, from the payload or a `pull_request` event's merge ref, or why neither names one. */
    private function pullRequestIn(Node|CannotTell $payload): PullRequestNumber|CannotTell
    {
        $number = ($payload instanceof Node ? $payload : Node::decode('{}'))->field('pull_request')->field('number');
        $ref = $this->variables->valueOf('GITHUB_REF');
        $isPullRequest = $this->variables->valueOf(self::EVENT) === 'pull_request';

        try {
            return match (true) {
                $number->isPresent() => PullRequestNumber::of($number->integer()),
                $isPullRequest && preg_match(self::PULL_REQUEST, $ref, $merge) === 1
                    => PullRequestNumber::parse($merge[1]),
                default => CannotTell::because(self::NO_PULL_REQUEST),
            };
        } catch (NotInShape $misread) {
            return CannotTell::because($misread->getMessage());
        }
    }

    /** The branch `GITHUB_REF` names; empty for a tag or a pull request's ref. */
    private function branch(): string
    {
        return preg_match(self::BRANCH, $this->variables->valueOf('GITHUB_REF'), $branch) === 1 ? $branch[1] : '';
    }

    private function payload(): Node|CannotTell
    {
        $event = $this->variables->valueOf('GITHUB_EVENT_PATH');
        $payload = is_file($event) ? file_get_contents($event) : false;

        return $payload === false
            ? CannotTell::because('No event payload could be read, so the default branch is not known.')
            : Node::decode($payload);
    }

    private function defaultBranchIn(Node|CannotTell $payload): Scope|CannotTell
    {
        if ($payload instanceof CannotTell) {
            return $payload;
        }

        try {
            return RunOn::branchNamed($payload->field('repository')->field('default_branch')->text());
        } catch (NotInShape) {
            return CannotTell::because('The event payload does not name the default branch.');
        }
    }

    private function matrixOf(Plan $plan): string
    {
        $matrix = [];

        foreach ($plan as $shard) {
            $matrix[] = ['id' => $shard->id()->number(), 'label' => $shard->label()];
        }

        return json_encode($matrix, self::JSON);
    }

    private function appended(string $file, string $line): Written|CannotJudge
    {
        return file_put_contents($file, $line, FILE_APPEND) === false
            ? CannotJudge::because(sprintf('%s could not be written.', $file))
            : Written::to($file);
    }
}
