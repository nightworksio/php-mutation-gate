<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Markdown;
use NightWorksIO\MutationGate\Adapter\GitHub\StepSummary;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

$clock = static fn(): StoppedClock => new StoppedClock('2026-09-30T12:00:00Z');

it('appends the summary to the step\'s', function () use ($clock): void {
    $root = Scratch::directory();
    Scratch::write($root, 'summary.md', "Earlier step\n");
    $file = sprintf('%s/summary.md', $root);

    expect(StepSummary::appendingTo($file, 'https://github.com/octo/gate/actions/runs/7', $clock())->report(Verdicts::failing()))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(sprintf("Earlier step\n%s", Markdown::summary(Verdicts::failing(), 'https://github.com/octo/gate/actions/runs/7', Verdicts::monthAgo())));
});

it('says why it wrote none', function () use ($clock): void {
    $root = Scratch::directory();

    expect(StepSummary::appendingTo('', '', $clock())->report(Verdicts::passing()))
        ->toEqual(NotWritten::because('GITHUB_STEP_SUMMARY is not set, so there is no step summary to write.'))
        ->and(StepSummary::appendingTo(sprintf('%s/none/summary.md', $root), '', $clock())->report(Verdicts::passing()))
        ->toEqual(NotWritten::because(sprintf('The step summary could not be written to %s/none/summary.md.', $root)));
});

it('reads where to write and the run to link from the environment', function () use ($clock): void {
    $read = static fn(?string $server, ?string $run): StepSummary => Environment::during(
        ['GITHUB_STEP_SUMMARY' => '/tmp/summary.md', 'GITHUB_SERVER_URL' => $server, 'GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_RUN_ID' => $run],
        static fn(): StepSummary => StepSummary::configured(Options::none(), $clock()),
    );

    expect($read(null, '7'))->toEqual(StepSummary::appendingTo('/tmp/summary.md', 'https://github.com/octo/gate/actions/runs/7', $clock()))
        ->and($read('https://github.example', '7'))
        ->toEqual(StepSummary::appendingTo('/tmp/summary.md', 'https://github.example/octo/gate/actions/runs/7', $clock()))
        ->and($read(null, null))->toEqual(StepSummary::appendingTo('/tmp/summary.md', '', $clock()));
});

it('counts what the default branch saved over the 30 days before its clock', function () use ($clock): void {
    $root = Scratch::directory();
    $file = sprintf('%s/summary.md', $root);
    Scratch::write($root, 'summary.md', '');
    $verdict = Verdicts::failing()->withAccount(Verdicts::account()->after(Trend::decode(
        '{"format": 1, "runs": [{"commit": "a", "time": "2026-08-31T11:59:59Z", "trees": {}, "runnerSeconds": 60, "fullRunSeconds": 3660}]}',
    )));

    StepSummary::appendingTo($file, '', $clock())->report($verdict);

    expect(file_get_contents($file))->toContain('In the last 30 days the gate saved 1h 27m of runner time.');
});
