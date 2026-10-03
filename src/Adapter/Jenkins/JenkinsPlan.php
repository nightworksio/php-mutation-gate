<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Jenkins;

use function getenv;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiJob;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\PlanListing;
use NightWorksIO\MutationGate\Core\Ci\Publication;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\CiPlan;

/**
 * The CI plan `jenkins` (ADR-0024 decision 1). The plan is printed as the
 * generic JSON, which the Jenkinsfile reads with `readJSON` and turns into
 * the map of closures it hands to `parallel`, one per shard, each of which
 * names its shard in `SHARD`, as WhichShard reads it on every CI. Jenkins does not name the default branch;
 * `ci.defaultBranch` does.
 */
final readonly class JenkinsPlan implements CiPlan, Configurable
{
    private const string NO_DEFAULT = 'Jenkins does not name the default branch. Set ci.defaultBranch.';

    private function __construct(private CiJob $job)
    {
    }

    /** The plan for this job. */
    public static function in(CiJob $job): self
    {
        return new self($job);
    }

    /** From `definition`, the Jenkinsfile that runs the gate, which `ci.jenkins.definition` names. */
    public static function fromOptions(Options $options): self|Invalid
    {
        return CiJob::planned($options, Variables::of(getenv()), self::in(...));
    }

    /** The plan, printed for the Jenkinsfile to read with `readJSON`. */
    public function publish(Plan $plan): Publication
    {
        return Publication::printed(PlanListing::of($plan));
    }

    /**
     * In a multibranch project: a pull request where `CHANGE_ID` is set; none for a tag, which `TAG_NAME` names
     * and which is no branch the gate writes for; else the branch `BRANCH_NAME` names.
     */
    public function runOn(): RunOn|CannotTell
    {
        $defaultBranch = CannotTell::because(self::NO_DEFAULT);
        $variables = $this->job->variables();

        return match (true) {
            $variables->has(Variables::CHANGE_ID) => RunOn::pullRequest(
                PullRequestNumber::parse($variables->valueOf(Variables::CHANGE_ID)),
                $defaultBranch,
            ),
            $variables->has(Variables::TAG_NAME) => RunOn::detached($defaultBranch),
            default => RunOn::branch($variables->valueOf(Variables::BRANCH_NAME), $defaultBranch),
        };
    }

    /** The Jenkinsfile that runs the gate. */
    public function definitions(): Paths
    {
        return $this->job->definitions();
    }

    /**
     * Nothing of Jenkins' own: a build holds only the credentials its Jenkinsfile binds, which a project adds to
     * `runner.withhold` (ADR-0024 decision 5).
     */
    public static function withheld(): Withheld
    {
        return Withheld::nothing();
    }

    /** Jenkins sets `BUILD_TAG` in every build. */
    public static function marker(): CiMarker
    {
        return CiMarker::setting(Variables::BUILD_TAG);
    }
}
