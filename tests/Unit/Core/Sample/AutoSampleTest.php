<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Sample\AutoSample;
use NightWorksIO\MutationGate\Core\Sample\ExpectedWork;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('never samples when off, whatever the work', function (): void {
    expect(AutoSample::off()->isOff())->toBeTrue()
        ->and(AutoSample::off()->samples(ExpectedWork::of(1_000_000, Seconds::days(30))))->toBeFalse()
        ->and(AutoSample::off()->said())->toBe('off')
        ->and(AutoSample::off()->written())->toBe('off');
});

it('samples above as many mutants beyond the new code, and not at them', function (int $mutants, bool $samples): void {
    expect(AutoSample::aboveMutants(500)->samples(ExpectedWork::of($mutants, Seconds::days(1))))->toBe($samples);
})->with([
    'below' => [499, false],
    'at' => [500, false],
    'above' => [501, true],
]);

it('samples above as long a runner time beyond the new code, and not at it, whatever the mutants', function (float $seconds, bool $samples): void {
    expect(AutoSample::aboveRunnerTime(Seconds::of(7200))->samples(ExpectedWork::of(1, Seconds::of($seconds))))->toBe($samples);
})->with([
    'below' => [7199.0, false],
    'at' => [7200.0, false],
    'above' => [7200.5, true],
]);

it('says and writes its threshold as a count or a duration', function (): void {
    expect(AutoSample::aboveMutants(20000)->isOff())->toBeFalse()
        ->and(AutoSample::aboveMutants(20000)->said())->toBe('20000 mutants')
        ->and(AutoSample::aboveMutants(20000)->written())->toBe(20000)
        ->and(AutoSample::aboveRunnerTime(Seconds::of(5400))->isOff())->toBeFalse()
        ->and(AutoSample::aboveRunnerTime(Seconds::of(5400))->said())->toBe('1h 30m of runner time')
        ->and(AutoSample::aboveRunnerTime(Seconds::of(5400))->written())->toBe('1h30m');
});
