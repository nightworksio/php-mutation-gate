<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('exits 1 on a verdict that failed', function (): void {
    expect(new Judged(Verdicts::failing(), [], Baseline::none())->exitCode())->toBe(ExitCode::Failed);
});

it('exits 0 on a verdict that passed', function (): void {
    expect(new Judged(Verdicts::passing(), ['Wrote memory.'], Baseline::none())->exitCode())->toBe(ExitCode::Passed);
});

it('keeps the verdict and the lines the run said beside it', function (): void {
    $verdict = Verdicts::passing();
    $judged = new Judged($verdict, ['Wrote memory.', 'The disk is full.'], Baseline::none());

    expect($judged->verdict)->toBe($verdict)
        ->and($judged->said)->toBe(['Wrote memory.', 'The disk is full.'])
        ->and($judged->baseline)->toEqual(Baseline::none());
});

it('says more lines after those it said, keeping the verdict and the baseline', function (): void {
    $verdict = Verdicts::passing();
    $baseline = Baseline::none();
    $judged = new Judged($verdict, ['Wrote memory.'], $baseline)->saying('Raised the floors.', 'src: 80');

    expect($judged->said)->toBe(['Wrote memory.', 'Raised the floors.', 'src: 80'])
        ->and($judged->verdict)->toBe($verdict)
        ->and($judged->baseline)->toBe($baseline);
});
