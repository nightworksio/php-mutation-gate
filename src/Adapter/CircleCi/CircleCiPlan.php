<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\CircleCi;

use function file_put_contents;
use function getenv;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\Definitions;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\CiPlan;

use function preg_match;

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

    private function __construct(private CiJob $job, private string $to)
    {
    }

    /** A plan that prints to this file. */
    public static function printing(string $to, Variables $variables): self
    {
        return new self(CiJob::of($variables, Paths::of(Path::of(Definitions::CIRCLECI))), $to);
    }

    public static function fromOptions(Options $options): self
    {
        return self::printing(Written::OUTPUT, Variables::of(getenv()));
    }

    public function publish(Plan $plan): Written|CannotJudge
    {
        return Written::attempted($this->to, file_put_contents($this->to, PlanListing::of($plan)));
    }

    public function shard(Plan $plan): ShardId|CannotJudge
    {
        return $this->job->shard($plan);
    }

    public function runOn(): RunOn|CannotTell
    {
        $defaultBranch = CannotTell::because('CircleCI does not name the default branch. Set ci.defaultBranch.');
        $variables = $this->job->variables();

        return match (true) {
            preg_match(self::PULL_REQUEST, $variables->valueOf('CIRCLE_PULL_REQUEST'), $number) === 1
                => RunOn::pullRequest(PullRequestNumber::parse($number[1]), $defaultBranch),
            $variables->valueOf('CIRCLE_TAG') !== '' => RunOn::detached($defaultBranch),
            default => RunOn::branch($variables->valueOf('CIRCLE_BRANCH'), $defaultBranch),
        };
    }

    /** The config CircleCI runs from the repository. */
    public function definitions(): Paths
    {
        return $this->job->definitions();
    }

    /** CircleCI sets `CIRCLECI` to `true` in every job. */
    public static function marker(): CiMarker
    {
        return CiMarker::saying(Variables::CIRCLECI);
    }

    /** The job's OpenID Connect tokens. */
    public static function withheld(): Withheld
    {
        return Withheld::of('CIRCLE_OIDC_TOKEN*');
    }
}
