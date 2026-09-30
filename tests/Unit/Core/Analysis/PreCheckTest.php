<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\CheckTime;
use NightWorksIO\MutationGate\Core\Analysis\PreCheck;
use NightWorksIO\MutationGate\Core\Analysis\RejectionRate;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Seconds;

$plus = Mutation::of('Plus', MutatorFamily::Arithmetic, "-+\n+-");
$learned = static fn(int $checks, int $rejections, float $seconds): AnalyserHistory => AnalyserHistory::of('phpstan')
    ->withRate(RejectionRate::of('Plus', $checks, $rejections))
    ->withTime(CheckTime::of(100, Seconds::of($seconds)));

it('checks a mutator before its tests until it holds fifty checks of it', function () use ($plus, $learned): void {
    expect(PreCheck::standard()->pays(AnalyserHistory::of('phpstan'), $plus, Seconds::of(0.0)))->toBeTrue()
        ->and(PreCheck::standard()->pays($learned(49, 0, 100.0), $plus, Seconds::of(0.0)))->toBeTrue()
        ->and(PreCheck::standard()->pays($learned(50, 0, 100.0), $plus, Seconds::of(9.0)))->toBeFalse();
});

it('checks a mutator the analyser never rejected only after its tests', function () use ($plus, $learned): void {
    expect(PreCheck::standard()->pays($learned(500, 0, 0.1), $plus, Seconds::of(60.0)))->toBeFalse();
});

it('checks before the tests where the rate times the tests\' time is more than one check\'s', function (float $tests, bool $pays) use ($plus, $learned): void {
    // a fifth of its mutants rejected, one check taking a second
    expect(PreCheck::standard()->pays($learned(50, 10, 100.0), $plus, Seconds::of($tests)))->toBe($pays);
})->with([
    'tests of 10s save 2s' => [10.0, true],
    'tests of 5s save exactly 1s' => [5.0, false],
    'tests of 2s save 0.4s' => [2.0, false],
]);

it('checks before the tests while no check has been timed', function () use ($plus): void {
    $untimed = AnalyserHistory::of('phpstan')->withRate(RejectionRate::of('Plus', 50, 1));

    expect(PreCheck::standard()->pays($untimed, $plus, Seconds::of(0.1)))->toBeTrue();
});
