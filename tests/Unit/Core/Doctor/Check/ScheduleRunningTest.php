<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Doctor\Asked;
use NightWorksIO\MutationGate\Core\Doctor\Check\ScheduleRunning;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\ForkApproval;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\Schedule;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Online;

$scheduled = static fn(Schedule|CannotTell $schedule): Observations => Online::observed(
    Online::settings(Listed::of('mutation / verdict'), ForkApproval::AllExternalContributors, $schedule),
);

$why = 'The scheduled run keeps the default branch\'s ledger current, which every pull request starts from.';

it('advises of each workflow running the gate that GitHub disabled for inactivity', function (int $disabled, string $said) use ($scheduled, $why): void {
    $workflows = array_map(static fn(int $number): Path => Path::of(sprintf('.github/workflows/gate-%d.yml', $number)), range(1, $disabled));

    expect(ScheduleRunning::in($scheduled(Schedule::of(Paths::of(...$workflows), Paths::of(...$workflows), NotGiven::value()))))
        ->toEqual(Findings::of(Finding::of(
            Slug::ScheduleNotRunning,
            Severity::Advice,
            $said,
            $why,
            'Enable each again, with gh workflow enable or in the Actions tab.',
        )));
})->with([
    'one' => [1, 'GitHub disabled .github/workflows/gate-1.yml after 60 days without activity, so it no longer runs on a schedule.'],
    'two' => [2, 'GitHub disabled .github/workflows/gate-1.yml, .github/workflows/gate-2.yml after 60 days without activity, so they no longer run on a schedule.'],
]);

it('advises where no workflow running the gate ran on a schedule in the last 8 days', function (Schedule $schedule) use ($scheduled, $why): void {
    expect(ScheduleRunning::in($scheduled($schedule)))->toEqual(Findings::of(Finding::of(
        Slug::ScheduleNotRunning,
        Severity::Advice,
        'No workflow of octo/gate that runs the gate has run on a schedule since 2026-09-22T12:00:00Z.',
        $why,
        'Run the gate on a schedule, such as on: schedule: [{cron: \'0 3 * * 1\'}] in its workflow.',
    )));
})->with([
    'nine days ago' => [Online::ranAt('2026-09-21T12:00:00Z')],
    'just over 8 days ago' => [Online::ranAt('2026-09-22T11:59:59Z')],
    'never' => [Schedule::of(Paths::of(Path::of('.github/workflows/mutation.yml')), Paths::none(), NotGiven::value())],
]);

it('finds nothing where the gate ran on a schedule within 8 days, no GitHub workflow runs it, or nothing was read', function () use ($scheduled): void {
    expect(ScheduleRunning::in($scheduled(Online::ranAt('2026-09-22T12:00:00Z'))))->toEqual(Findings::none())
        ->and(ScheduleRunning::in($scheduled(Schedule::of(Paths::none(), Paths::none(), NotGiven::value()))))->toEqual(Findings::none())
        ->and(ScheduleRunning::in($scheduled(CannotTell::because('403'))))->toEqual(Findings::none())
        ->and(ScheduleRunning::in(Observations::none()->withAsked(Asked::nothing()->withGitHub(Online::wellSet()))))->toEqual(Findings::none())
        ->and(ScheduleRunning::in(Observations::none()))->toEqual(Findings::none());
});
