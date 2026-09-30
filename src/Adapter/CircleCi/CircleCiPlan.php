<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\CircleCi;

use function file_put_contents;
use function getenv;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Port\CiPlan;

use function preg_match;
use function sprintf;

/**
 * The CI plan `circleci`. CircleCI's parallelism is fixed in its config, so
 * the plan job cuts `--shards=<n>` to match it, and the plan is printed as the
 * generic JSON. Each node's shard is `CIRCLE_NODE_INDEX` + 1, and a
 * `CIRCLE_NODE_TOTAL` that is not the plan's count of shards cannot be judged.
 * CircleCI does not name the default branch; `ci.defaultBranch` does.
 */
final readonly class CircleCiPlan implements CiPlan, Configurable
{
    private const string PULL_REQUEST = '#/pull/(\d+)$#';

    private const string DEFINITION = '.circleci/config.yml';

    private function __construct(private Variables $variables, private string $to)
    {
    }

    /** A plan that prints to this file. */
    public static function printing(string $to, Variables $variables): self
    {
        return new self($variables, $to);
    }

    public static function fromOptions(Options $options): self
    {
        return self::printing('php://output', Variables::of(getenv()));
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

    public function runOn(): RunOn|CannotTell
    {
        $defaultBranch = CannotTell::because('CircleCI does not name the default branch. Set ci.defaultBranch.');

        return match (true) {
            preg_match(self::PULL_REQUEST, $this->variables->valueOf('CIRCLE_PULL_REQUEST'), $number) === 1
                => RunOn::pullRequest(PullRequestNumber::parse($number[1]), $defaultBranch),
            $this->variables->valueOf('CIRCLE_TAG') !== '' => RunOn::detached($defaultBranch),
            default => RunOn::branch($this->variables->valueOf('CIRCLE_BRANCH'), $defaultBranch),
        };
    }

    /** The config CircleCI runs from the repository. */
    public function definitions(): Paths
    {
        return Paths::of(Path::of(self::DEFINITION));
    }

    /** The job's OpenID Connect tokens. */
    public function withheld(): Withheld
    {
        return Withheld::of('CIRCLE_OIDC_TOKEN*');
    }
}
