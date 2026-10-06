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

it('says a limit allowed covering tests their three times only where it is more than three times their time', function (float $tests, float $limit, bool $allowed): void {
    expect(MutantLimit::standard()->allowed(Seconds::of($tests), Seconds::of($limit)))->toBe($allowed);
})->with([
    'a limit the formula decided' => [10.0, 35.0, true],
    'a limit the floor decided' => [0.1, 10.0, true],
    'a limit just over three times' => [3.0, 9.01, true],
    'a limit of exactly three times' => [3.0, 9.0, false],
    'a limit of twice' => [3.0, 6.0, false],
    'a limit the most decided under three times' => [150.0, 300.0, false],
]);
