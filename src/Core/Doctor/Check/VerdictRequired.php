<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function in_array;

use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\GitHubSettings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * The default branch not requiring the verdict's check-run, `ci.check`
 * (ADR-0017, decision 9): by a ruleset or by branch protection.
 */
final readonly class VerdictRequired
{
    private const string FOUND = '%s\'s default branch, %s, does not require the check %s.';

    private const string WHY = 'A pull request whose verdict failed can still be merged.';

    private const string FIX = 'Require the status check %s on %s, in a ruleset or a branch protection rule.';

    public static function in(Observations $observed): Findings
    {
        $gitHub = $observed->asked()->gitHub();
        $settings = $observed->settings();

        return $gitHub instanceof GitHubSettings && $settings instanceof Settings
            ? self::of($gitHub, $settings->ci()->check())
            : Findings::none();
    }

    private static function of(GitHubSettings $gitHub, string $check): Findings
    {
        $required = $gitHub->required();

        return $required instanceof Listed && ! in_array($check, [...$required], strict: true)
            ? Findings::of(Finding::of(
                Slug::VerdictNotRequired,
                Severity::Advice,
                sprintf(self::FOUND, $gitHub->repository(), $gitHub->defaultBranch(), $check),
                self::WHY,
                sprintf(self::FIX, $check, $gitHub->defaultBranch()),
            ))
            : Findings::none();
    }
}
