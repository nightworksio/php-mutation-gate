<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Sample\ExpectedWork;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('is as many mutants as a plan expects to run, and the runner time they take', function (): void {
    $work = ExpectedWork::of(120, Seconds::of(90));

    expect($work->mutants())->toBe(120)
        ->and($work->runnerTime())->toEqual(Seconds::of(90));
});

it('says the work as a plan does, naming one mutant alone', function (int $mutants, string $said): void {
    expect(ExpectedWork::of($mutants, Seconds::of(7_800))->said())->toBe(sprintf('about %s, taking about 2h 10m of runner time', $said));
})->with([
    'none' => [0, '0 mutants'],
    'one' => [1, '1 mutant'],
    'many' => [1_200, '1200 mutants'],
]);
