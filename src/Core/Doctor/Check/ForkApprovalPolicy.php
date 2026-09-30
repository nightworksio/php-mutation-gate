<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\ForkApproval;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\GitHubSettings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * Workflows of pull requests from forks that run without a maintainer's
 * approval (ADR-0017, decision 9; ADR-0019, decision 3): anything short of
 * approval for every outside contributor.
 */
final readonly class ForkApprovalPolicy
{
    private const string FOUND = '%s runs the workflows of a fork\'s pull request unapproved unless its author is %s.';

    private const string WHY
        = 'A fork\'s pull request runs its own code in the gate\'s jobs, and can spend the runners\' minutes.';

    private const string FIX = <<<'FIX'
        Require approval for all external contributors, under Settings, Actions, General,
        in the approval for running fork pull request workflows from contributors.
        FIX;

    public static function in(Observations $observed): Findings
    {
        $gitHub = $observed->asked()->gitHub();

        return $gitHub instanceof GitHubSettings ? self::of($gitHub) : Findings::none();
    }

    private static function of(GitHubSettings $gitHub): Findings
    {
        $policy = $gitHub->forkApproval();

        return $policy instanceof ForkApproval && $policy !== ForkApproval::AllExternalContributors
            ? Findings::of(Finding::of(
                Slug::ForkApprovalWeak,
                Severity::Advice,
                sprintf(self::FOUND, $gitHub->repository(), self::who($policy)),
                self::WHY,
                self::FIX,
            ))
            : Findings::none();
    }

    private static function who(ForkApproval $policy): string
    {
        return $policy === ForkApproval::FirstTimeContributors
            ? 'a first-time contributor'
            : 'a first-time contributor new to GitHub';
    }
}
