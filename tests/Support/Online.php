<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Doctor\Asked;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\ForkApproval;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\GitHubSettings;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\Schedule;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Time\Instant;

/** What `--online` reads of `octo/gate`, as the doctor's checks are given it on 2026-09-30. */
final readonly class Online
{
    /** A repository set as the gate needs: the verdict required, every fork approved, a schedule run yesterday. */
    public static function wellSet(): GitHubSettings
    {
        return self::settings(
            Listed::of('build', 'mutation / verdict'),
            ForkApproval::AllExternalContributors,
            self::ranAt('2026-09-29T03:00:00Z'),
        );
    }

    /**
     * @param Listed<string>|CannotTell $required
     */
    public static function settings(
        Listed|CannotTell $required,
        ForkApproval|CannotTell $forkApproval,
        Schedule|CannotTell $schedule,
    ): GitHubSettings {
        return GitHubSettings::of('octo/gate', 'main', $required, $forkApproval, $schedule);
    }

    /** The gate's one workflow, active, last run on a schedule at this instant. */
    public static function ranAt(string $instant): Schedule
    {
        return Schedule::of(
            Paths::of(Path::of('.github/workflows/mutation.yml')),
            Paths::none(),
            Instant::at(new DateTimeImmutable($instant)),
        );
    }

    /** Observations of the settings of a project that runs Pest, as of 2026-09-30, with what GitHub showed. */
    public static function observed(GitHubSettings|CannotTell $gitHub): Observations
    {
        return Observations::none()
            ->at(new DateTimeImmutable('2026-09-30T12:00:00Z'))
            ->withSettings(Configs::settings(['runner' => 'pest']))
            ->withAsked(Asked::nothing()->withGitHub($gitHub));
    }
}
