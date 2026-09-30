<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\GitHubSettings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * What `--online` could not read (ADR-0017, decision 9): no repository on
 * GitHub to read, or a setting GitHub would not show, each with the
 * permission that shows it. Neither changes whether a run fails.
 */
final readonly class OnlineRead
{
    private const string NO_REPOSITORY = '--online found no repository on GitHub. %s';

    private const string NO_REPOSITORY_WHY = '--online reads GitHub\'s settings alone; every other check still ran.';

    private const string NO_REPOSITORY_FIX
        = 'Run doctor --online where GITHUB_REPOSITORY names the repository, or git\'s origin remote is on GitHub.';

    private const string UNREAD = 'GitHub did not show %s of %s. %s';

    private const string UNREAD_WHY = 'doctor cannot tell whether it is set as the gate needs.';

    private const string UNREAD_FIX = 'Run doctor --online with a GITHUB_TOKEN or GH_TOKEN that has %s.';

    public static function in(Observations $observed): Findings
    {
        $gitHub = $observed->asked()->gitHub();

        return match (true) {
            $gitHub instanceof CannotTell => Findings::of(Finding::of(
                Slug::NoGitHubRepository,
                Severity::Advice,
                sprintf(self::NO_REPOSITORY, $gitHub->why()),
                self::NO_REPOSITORY_WHY,
                self::NO_REPOSITORY_FIX,
            )),
            $gitHub instanceof GitHubSettings => self::unread($gitHub),
            default => Findings::none(),
        };
    }

    private static function unread(GitHubSettings $gitHub): Findings
    {
        $required = $gitHub->required();
        $forks = $gitHub->forkApproval();
        $schedule = $gitHub->schedule();

        return Findings::none()->and(
            $required instanceof CannotTell
                ? self::finding('the checks the default branch requires', $gitHub, $required, 'contents: read')
                : Findings::none(),
            $forks instanceof CannotTell
                ? self::finding('the fork approval policy', $gitHub, $forks, 'administration: read')
                : Findings::none(),
            $schedule instanceof CannotTell
                ? self::finding('the scheduled runs', $gitHub, $schedule, 'actions: read')
                : Findings::none(),
        );
    }

    private static function finding(string $setting, GitHubSettings $gitHub, CannotTell $why, string $grant): Findings
    {
        return Findings::of(Finding::of(
            Slug::OnlineUnread,
            Severity::Advice,
            sprintf(self::UNREAD, $setting, $gitHub->repository(), $why->why()),
            self::UNREAD_WHY,
            sprintf(self::UNREAD_FIX, $grant),
        ));
    }
}
