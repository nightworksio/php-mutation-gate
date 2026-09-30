<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function count;

use DateTimeImmutable;

use function implode;

use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\GitHubSettings;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\Schedule;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * No scheduled run of the gate in the last 8 days (ADR-0017, decision 9),
 * where a GitHub workflow runs it: one GitHub disabled after 60 days without
 * activity, or none that ran on a schedule. The scheduled run keeps the default branch's ledger
 * and the survivors' list current, so every pull request starts from them.
 */
final readonly class ScheduleRunning
{
    /** How long since the last scheduled run before it counts as not running: a week, and a day to spare. */
    private const string WITHIN = '-8 days';

    private const string DISABLED
        = 'GitHub disabled %s after 60 days without activity, so %s on a schedule.';

    private const string DISABLED_FIX = 'Enable each again, with gh workflow enable or in the Actions tab.';

    private const string NOT_RUN = 'No workflow of %s that runs the gate has run on a schedule since %s.';

    private const string NOT_RUN_FIX
        = 'Run the gate on a schedule, such as on: schedule: [{cron: \'0 3 * * 1\'}] in its workflow.';

    private const string WHY
        = 'The scheduled run keeps the default branch\'s ledger current, which every pull request starts from.';

    public static function in(Observations $observed): Findings
    {
        $gitHub = $observed->asked()->gitHub();
        $run = $observed->run();
        $now = $run instanceof DoctorRun ? $run->now() : $run;

        return $gitHub instanceof GitHubSettings && $now instanceof DateTimeImmutable
            ? self::scheduled($gitHub, Instant::at($now->modify(self::WITHIN)))
            : Findings::none();
    }

    private static function scheduled(GitHubSettings $gitHub, Instant $since): Findings
    {
        $schedule = $gitHub->schedule();

        return $schedule instanceof Schedule && count($schedule->workflows()) > 0
            ? self::of($schedule, $since, $gitHub->repository())
            : Findings::none();
    }

    private static function of(Schedule $schedule, Instant $since, string $repository): Findings
    {
        $disabled = [];

        foreach ($schedule->disabled() as $workflow) {
            $disabled[] = $workflow->value();
        }

        $lastRun = $schedule->lastRun();

        return match (true) {
            $disabled !== [] => self::finding(
                sprintf(
                    self::DISABLED,
                    implode(', ', $disabled),
                    count($disabled) === 1 ? 'it no longer runs' : 'they no longer run',
                ),
                self::DISABLED_FIX,
            ),
            ! $lastRun instanceof Instant || $since->isAfter($lastRun) => self::finding(
                sprintf(self::NOT_RUN, $repository, $since->value()),
                self::NOT_RUN_FIX,
            ),
            default => Findings::none(),
        };
    }

    private static function finding(string $found, string $fix): Findings
    {
        return Findings::of(Finding::of(Slug::ScheduleNotRunning, Severity::Advice, $found, self::WHY, $fix));
    }
}
