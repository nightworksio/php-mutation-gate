<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\BuildSummary;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Cost\SavingsWindow;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes the summary to a file and prints the logging command that attaches it', function (): void {
    $root = Scratch::directory();
    $commands = sprintf('%s/commands', $root);
    $file = sprintf('%s/mutation-gate-summary.md', $root);
    $summary = BuildSummary::printingTo($commands, $root, 'https://dev.azure.com/acme/shop/_build/results?buildId=9', new StoppedClock('2026-09-30T12:00:00Z'), SavingsWindow::standard());

    expect($summary->report(Verdicts::failing()))->toEqual(Written::to($file))
        ->and(file_get_contents($file))->toBe(Markdown::summary(Verdicts::failing(), 'https://dev.azure.com/acme/shop/_build/results?buildId=9', SavingsWindow::standard(), Verdicts::clockNow()))
        ->and(file_get_contents($commands))->toBe(sprintf("##vso[task.uploadsummary]%s\n", $file));
});

it('says why it wrote none', function (): void {
    $root = Scratch::directory();
    $missing = sprintf('%s/none', $root);
    $clock = new StoppedClock('2026-09-30T12:00:00Z');

    expect(BuildSummary::printingTo(sprintf('%s/commands', $root), '', '', $clock, SavingsWindow::standard())->report(Verdicts::passing()))
        ->toEqual(NotWritten::because('AGENT_TEMPDIRECTORY is not set, so there is no build summary to write.'))
        ->and(BuildSummary::printingTo(sprintf('%s/commands', $root), $missing, '', $clock, SavingsWindow::standard())->report(Verdicts::passing()))
        ->toEqual(NotWritten::because(sprintf('The build summary could not be written to %s/mutation-gate-summary.md.', $missing)))
        ->and(file_exists(sprintf('%s/commands', $root)))->toBeFalse();
});

it('reads its directory and the run to link from the job\'s variables', function (): void {
    $summary = BuildSummary::configured(Options::none(), Variables::of([]), new StoppedClock('2026-09-30T12:00:00Z'));

    expect($summary->report(Verdicts::passing()))
        ->toEqual(NotWritten::because('AGENT_TEMPDIRECTORY is not set, so there is no build summary to write.'));
});
