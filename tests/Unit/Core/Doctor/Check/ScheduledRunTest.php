<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\ScheduledRun;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\GateDefinitions;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

$observed = static fn(GateDefinitions $running): Observations => Observations::none()
    ->withFiles(ProjectFiles::none()->withRunningTheGate($running));

it('advises a schedule where no GitHub workflow that runs the gate has one', function () use ($observed): void {
    $running = GateDefinitions::none()
        ->with(Path::of('.github/workflows/ci.yml'), "on:\n  pull_request:\n# schedule: is off\n")
        ->with(Path::of('.github/workflows/gate.yml'), "on:\n  push:\n")
        ->with(Path::of('.gitlab-ci.yml'), "schedule:\n");

    expect(ScheduledRun::in($observed($running)))->toEqual(Findings::of(Finding::of(
        Slug::NoScheduledRun,
        Severity::Advice,
        'No schedule triggers .github/workflows/ci.yml, .github/workflows/gate.yml, the workflows that run the gate.',
        'A full run on a schedule reaches the units no change reaches, and judges them with the gate of the day.',
        'Run the gate on a schedule, such as on: schedule: [{cron: \'0 3 * * 1\'}] in its workflow.',
    )));
});

it('finds nothing where a workflow has a schedule, in either spelling', function (string $text) use ($observed): void {
    $running = GateDefinitions::none()
        ->with(Path::of('.github/workflows/ci.yml'), "on:\n  pull_request:\n")
        ->with(Path::of('.github/workflows/nightly.yml'), $text);

    expect(ScheduledRun::in($observed($running)))->toEqual(Findings::none());
})->with([
    'a block' => ["on:\n  schedule:\n    - cron: '0 3 * * 1'\n"],
    'a flow' => ["on: {schedule: [{cron: '0 3 * * 1'}]}\n"],
]);

it('finds nothing where no GitHub workflow runs the gate, or nothing says what does', function () use ($observed): void {
    $elsewhere = GateDefinitions::none()->with(Path::of('.gitlab-ci.yml'), "script: mutation-gate\n");

    expect(ScheduledRun::in($observed(GateDefinitions::none())))->toEqual(Findings::none())
        ->and(ScheduledRun::in($observed($elsewhere)))->toEqual(Findings::none())
        ->and(ScheduledRun::in(Observations::none()))->toEqual(Findings::none());
});
