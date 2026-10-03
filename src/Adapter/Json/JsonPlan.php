<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Json;

use function getenv;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\CiPlan;

/**
 * The CI plan `json`, for any CI the others do not cover and for a local run:
 * the plan printed as the generic JSON, and each job's shard named by
 * `--shard` or by the variables the generic parallel jobs of GitLab and
 * Buildkite set. It knows nothing of the run's ref, which the gate asks git.
 */
final readonly class JsonPlan implements CiPlan, Configurable
{
    private function __construct(private CiJob $job)
    {
    }

    /** The plan for a job with these variables. */
    public static function in(Variables $variables): self
    {
        return new self(CiJob::of($variables, Paths::none()));
    }

    public static function fromOptions(Options $options): self
    {
        return self::in(Variables::of(getenv()));
    }

    public function publish(Plan $plan): Publication
    {
        return Publication::printed(PlanListing::of($plan));
    }

    /** None: the JSON plan runs no definition of its own. */
    public function definitions(): Paths
    {
        return $this->job->definitions();
    }

    public function runOn(): CannotTell
    {
        return CannotTell::because('The JSON plan knows nothing of the run, so git names its branch.');
    }

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
