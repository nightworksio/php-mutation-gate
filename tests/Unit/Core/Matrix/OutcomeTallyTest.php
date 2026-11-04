<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Matrix\Outcome;
use NightWorksIO\MutationGate\Core\Matrix\OutcomeTally;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

$tests = static fn(int $count): TestIds => TestIds::of(...array_map(static fn(int $at): TestId => TestId::of(sprintf('T%d::t', $at)), range(1, $count)));

it('counts no test to begin with', function (): void {
    expect(OutcomeTally::none())->toHaveCount(0)
        ->and(OutcomeTally::none()->of(Outcome::Killed))->toBe(0)
        ->and(OutcomeTally::none()->has(Outcome::Killed))->toBeFalse()
        ->and(OutcomeTally::none()->ran())->toBe(0);
});

it('adds tests to an outcome without changing the tally it came from', function () use ($tests): void {
    $tally = OutcomeTally::none()->with(Outcome::Killed, $tests(1));
    $more = $tally->with(Outcome::Killed, $tests(2))->with(Outcome::NotRun, $tests(4));

    expect($more->of(Outcome::Killed))->toBe(3)
        ->and($more->of(Outcome::NotRun))->toBe(4)
        ->and($more->has(Outcome::NotRun))->toBeTrue()
        ->and($more->has(Outcome::Unknown))->toBeFalse()
        ->and($more)->toHaveCount(7)
        ->and($tally->of(Outcome::Killed))->toBe(1);
});

it('counts the tests that ran to an answer: those that killed the mutant and those that passed with it', function () use ($tests): void {
    $tally = OutcomeTally::none()->with(Outcome::Killed, $tests(1))->with(Outcome::Passed, $tests(2))->with(Outcome::NotRun, $tests(4))->with(Outcome::Unknown, $tests(8));

    expect($tally->ran())->toBe(3)
        ->and($tally)->toHaveCount(15);
});
