<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** The bounds of 10 s and 300 s, the standard `timeouts.seconds` and `timeouts.most`. */
function standardBounds(): LimitBounds
{
    return LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0));
}

it('allows a mutant 5 s plus three times its covering tests\' own time, kept between the floor and the most', function (float $tests, float $limit): void {
    expect(MutantLimit::standard()->of(Seconds::of($tests), standardBounds()))->toEqual(Seconds::of($limit));
})->with([
    'tests that take no time get the floor' => [0.0, 10.0],
    'tests whose limit would be just under the floor get the floor' => [1.5, 10.0],
    'tests whose limit is the floor' => [5.0 / 3, 10.0],
    'tests whose limit is just over the floor' => [2.0, 11.0],
    'tests of ten seconds' => [10.0, 35.0],
    'tests whose limit is just under the most' => [98.0, 299.0],
    'tests whose limit would pass the most get the most' => [99.0, 300.0],
    'tests that take the most get the most' => [300.0, 300.0],
]);

it('allows a mutant the floor where its covering tests\' own time is not measured', function (): void {
    expect(MutantLimit::standard()->of(Unmeasured::duration(), standardBounds()))->toEqual(Seconds::of(10.0));
});

it('allows a mutant Infection\'s own 5 s plus five times its tests\' time, up to Infection\'s cap', function (float $tests, float $limit): void {
    expect(MutantLimit::infections()->of(Seconds::of($tests), LimitBounds::upTo(Seconds::of(300.0))))->toEqual(Seconds::of($limit));
})->with([
    'tests that take no time' => [0.0, 5.0],
    'tests that take a second' => [1.0, 10.0],
    'tests whose limit would pass the cap' => [60.0, 300.0],
]);

it('allows a mutant three times the start-up its run measured, in place of 5 s, plus three times its tests\' own time', function (float $startUp, float $tests, float $limit): void {
    expect(MutantLimit::standard()->of(Seconds::of($tests), standardBounds()->startingIn(Seconds::of($startUp))))
        ->toEqual(Seconds::of($limit));
})->with([
    'a start-up whose limit is under the floor gets the floor' => [1.0, 1.0, 10.0],
    'a slow start-up raises the limit past the floor' => [4.0, 0.5, 13.5],
    'a fast start-up lowers it below 5 s plus the tests' => [1.0, 4.0, 15.0],
    'a start-up whose limit would pass the most gets the most' => [101.0, 0.0, 300.0],
]);

it('keeps Infection\'s own 5 s, whatever start-up the run measured', function (): void {
    $bounds = LimitBounds::upTo(Seconds::of(300.0))->startingIn(Seconds::of(4.0));

    expect(MutantLimit::infections()->of(Seconds::of(1.0), $bounds))->toEqual(Seconds::of(10.0));
});
