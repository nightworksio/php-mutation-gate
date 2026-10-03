<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('allows a mutant 5 s plus five times its covering tests\' own time, never more than the cap', function (float $tests, float $limit): void {
    expect(MutantLimit::standard()->of(Seconds::of($tests), Seconds::of(30.0)))->toEqual(Seconds::of($limit));
})->with([
    'tests that take no time' => [0.0, 5.0],
    'tests that take a second' => [1.0, 10.0],
    'tests just under the cap' => [4.9, 29.5],
    'tests that would pass the cap' => [5.0, 30.0],
    'tests that take the cap' => [30.0, 30.0],
]);

it('allows a mutant the cap where its covering tests\' own time is not measured', function (): void {
    expect(MutantLimit::standard()->of(Unmeasured::duration(), Seconds::of(30.0)))->toEqual(Seconds::of(30.0));
});
