<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Json;

use function file_put_contents;
use function getenv;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\CiPlan;

use function sprintf;

/**
 * The CI plan `json`, for any CI the others do not cover and for a local run:
 * the plan printed as the generic JSON, and each job's shard named by
 * `--shard` or by the variables the generic parallel jobs of GitLab and
 * Buildkite set. It knows nothing of the run's ref, which the gate asks git.
 */
final readonly class JsonPlan implements CiPlan, Configurable
{
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

    public function runOn(): CannotTell
    {
        return CannotTell::because('The JSON plan knows nothing of the run, so git names its branch.');
    }

    /** None: the JSON plan runs no definition of its own. */
    public function definitions(): Paths
    {
        return Paths::none();
    }

    /** None: the JSON plan knows no CI. */
    /** The JSON plan marks no CI: a job takes it where no other plan's CI is marked. */
    public static function marker(): CiMarker
    {
        return CiMarker::none();
    }

    public static function withheld(): Withheld
    {
        return Withheld::nothing();
    }
}
