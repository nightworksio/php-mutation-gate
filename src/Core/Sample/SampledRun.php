<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Scope;

/**
 * A kind of run that may sample of its own accord, as `sampling.autoRuns`
 * lists them (ADR-0086). The default branch, a tag and a detached run never
 * do: the default branch's verdict writes what every other scope trusts,
 * and the others are the backstops a sampled pass leans on.
 */
enum SampledRun: string
{
    /** A pull request's run. */
    case PullRequests = 'pullRequests';

    /** A merge group's run, which merges once it passes. */
    case MergeGroups = 'mergeGroups';

    /** A push to a branch other than the default. */
    case Branches = 'branches';

    /** The kind of a run; none for one that never samples of its own accord. */
    public static function of(RunOn $run): self|NotGiven
    {
        $scope = $run->scope();
        $default = $run->defaultBranch();

        return match (true) {
            $run->isPullRequest() => self::PullRequests,
            $run->isProposed() => self::MergeGroups,
            ! $scope instanceof Scope, ! $default instanceof Scope, $scope->equals($default) => NotGiven::value(),
            $scope->isBranch() => self::Branches,
            default => NotGiven::value(),
        };
    }
}
