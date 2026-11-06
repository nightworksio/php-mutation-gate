<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\PreCheck;
use NightWorksIO\MutationGate\Core\Analysis\RejectionRate;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\KillsLearned;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Time\Seconds;

$makePlus = static fn(): Mutation => Mutation::of('Plus', MutatorFamily::Arithmetic, "-+\n+-");
$learned = static fn(int $checks, int $rejections): AnalyserHistory => AnalyserHistory::of('phpstan')
    ->withRate(RejectionRate::of('Plus', $checks, $rejections));

it('checks a mutator before its tests until its history and this run\'s checks before the tests hold fifty of it', function () use ($makePlus, $learned): void {
    $plus = $makePlus();
    $second = Seconds::of(1.0);
    $pays = static fn(AnalyserHistory $history, int $pending): bool => PreCheck::standard()->pays($history, $plus, Seconds::of(1.5), $second, $pending);

    expect($pays(AnalyserHistory::of('phpstan'), 0))->toBeTrue()
        ->and($pays(AnalyserHistory::of('phpstan'), 49))->toBeTrue()
        ->and($pays(AnalyserHistory::of('phpstan'), 50))->toBeFalse()
        ->and($pays($learned(49, 0), 0))->toBeTrue()
        ->and($pays($learned(30, 0), 19))->toBeTrue()
        ->and($pays($learned(30, 0), 20))->toBeFalse()
        ->and($pays($learned(50, 0), 0))->toBeFalse();
});

it('checks a mutant only after its tests where they take no longer than one check, learned or not', function () use ($makePlus, $learned): void {
    $plus = $makePlus();
    $pays = static fn(AnalyserHistory $history, float $tests): bool => PreCheck::standard()->pays($history, $plus, Seconds::of($tests), Seconds::of(2.0), 0);

    expect($pays(AnalyserHistory::of('phpstan'), 2.0))->toBeFalse()
        ->and($pays(AnalyserHistory::of('phpstan'), 2.5))->toBeTrue()
        ->and($pays($learned(50, 50), 2.0))->toBeFalse()
        ->and($pays($learned(50, 50), 2.5))->toBeTrue();
});

it('checks a mutator the analyser never rejected only after its tests', function () use ($makePlus, $learned): void {
    expect(PreCheck::standard()->pays($learned(500, 0), $makePlus(), Seconds::of(60.0), Seconds::of(0.001), 0))->toBeFalse();
});

it('checks before the tests where the rate times the tests\' time is more than one check\'s', function (float $tests, bool $pays) use ($makePlus, $learned): void {
    expect(PreCheck::standard()->pays($learned(50, 10), $makePlus(), Seconds::of($tests), Seconds::of(1.0), 0))->toBe($pays);
})->with([
    'tests of 10s save 2s' => [10.0, true],
    'tests of 5s save exactly 1s' => [5.0, false],
    'tests of 2s save 0.4s' => [2.0, false],
]);

it('weighs a check against its tests at the pace the plan learned kills take, and their own time where it learned none', function (Briefing $briefing, float $covering, bool $pays) use ($makePlus, $learned): void {
    expect(PreCheck::briefed($briefing)->pays($learned(50, 10), $makePlus(), Seconds::of($covering), Seconds::of(1.0), 0))->toBe($pays);
})->with([
    'a quarter of tests of 40s saves 2s' => [fn(): Briefing => Briefing::standard()->learning(KillsLearned::none()->pacing(Multiple::of(0.25))), 40.0, true],
    'a quarter of tests of 10s saves 0.5s' => [fn(): Briefing => Briefing::standard()->learning(KillsLearned::none()->pacing(Multiple::of(0.25))), 10.0, false],
    'a quarter of tests of 4s takes no longer than one check' => [fn(): Briefing => Briefing::standard()->learning(KillsLearned::none()->pacing(Multiple::of(0.25))), 4.0, false],
    'twice tests of 4s saves 1.6s' => [fn(): Briefing => Briefing::standard()->learning(KillsLearned::none()->pacing(Multiple::of(2.0))), 4.0, true],
    'no pace learned, tests of 10s save 2s' => [fn(): Briefing => Briefing::standard()->learning(KillsLearned::none()->allowing(Multiple::of(4.0))), 10.0, true],
]);
