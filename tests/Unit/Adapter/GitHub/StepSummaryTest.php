<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Markdown;
use NightWorksIO\MutationGate\Adapter\GitHub\StepSummary;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('appends the summary to the step\'s', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'summary.md', "Earlier step\n");
    $file = sprintf('%s/summary.md', $root);

    expect(StepSummary::appendingTo($file, 'https://github.com/octo/gate/actions/runs/7')->report(Verdicts::failing()))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(sprintf("Earlier step\n%s", Markdown::summary(Verdicts::failing(), 'https://github.com/octo/gate/actions/runs/7')));
});

it('says why it wrote none', function (): void {
    $root = Scratch::directory();

    expect(StepSummary::appendingTo('', '')->report(Verdicts::passing()))
        ->toEqual(NotWritten::because('GITHUB_STEP_SUMMARY is not set, so there is no step summary to write.'))
        ->and(StepSummary::appendingTo(sprintf('%s/none/summary.md', $root), '')->report(Verdicts::passing()))
        ->toEqual(NotWritten::because(sprintf('The step summary could not be written to %s/none/summary.md.', $root)));
});

it('reads where to write and the run to link from the environment', function (): void {
    $read = static fn(?string $server, ?string $run): StepSummary => Environment::during(
        ['GITHUB_STEP_SUMMARY' => '/tmp/summary.md', 'GITHUB_SERVER_URL' => $server, 'GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_RUN_ID' => $run],
        static fn(): StepSummary => StepSummary::fromOptions(Options::none()),
    );

    expect($read(null, '7'))->toEqual(StepSummary::appendingTo('/tmp/summary.md', 'https://github.com/octo/gate/actions/runs/7'))
        ->and($read('https://github.example', '7'))
        ->toEqual(StepSummary::appendingTo('/tmp/summary.md', 'https://github.example/octo/gate/actions/runs/7'))
        ->and($read(null, null))->toEqual(StepSummary::appendingTo('/tmp/summary.md', ''));
});
