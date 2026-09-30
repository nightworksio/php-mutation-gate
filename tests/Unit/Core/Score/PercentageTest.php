<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NotAPercentage;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Score\Score;

it('keeps a percentage as written in hundredths, truncated rather than rounded', function (int|float $percent, int $hundredths): void {
    $parsed = Percentage::parse($percent);

    expect($parsed)->toBeInstanceOf(Percentage::class)
        ->and($parsed instanceof Percentage ? $parsed->hundredths() : -1)->toBe($hundredths);
})->with([
    'two decimals' => [83.41, 8341],
    'more decimals' => [83.419, 8341],
    'binary noise below' => [0.29, 29],
    'whole' => [100, 10_000],
    'none' => [0, 0],
]);

it('says why a number outside 0 to 100 is no percentage', function (int|float $percent, string $message): void {
    $parsed = Percentage::parse($percent);

    expect($parsed)->toBeInstanceOf(NotAPercentage::class)
        ->and($parsed instanceof NotAPercentage ? $parsed->getMessage() : '')->toBe($message);
})->with([
    'below' => [-0.01, '-0.01 is not a percentage from 0 to 100.'],
    'above' => [100.01, '100.01 is not a percentage from 0 to 100.'],
]);

it('takes hundredths from 0 to 10000 and nothing outside them', function (): void {
    expect(Percentage::inHundredths(0))->toEqual(Percentage::parse(0))
        ->and(Percentage::inHundredths(10_000))->toEqual(Percentage::parse(100))
        ->and(Percentage::inHundredths(-1))->toEqual(NotAPercentage::of(-0.01))
        ->and(Percentage::inHundredths(10_001))->toEqual(NotAPercentage::of(100.01));
});

it('takes a part of a whole, truncated', function (): void {
    expect(Percentage::share(2, 3))->toEqual(Percentage::inHundredths(6_666))
        ->and(Percentage::share(3, 3))->toEqual(Percentage::parse(100))
        ->and(Percentage::share(3, 2))->toEqual(NotAPercentage::of(150));
});

it('gives back the percent it holds', function (): void {
    $percentage = Percentage::parse(61.2);

    expect($percentage instanceof Percentage ? $percentage->percent() : 0.0)->toBe(61.2);
});

it('writes a whole number where it is one, and no trailing zero otherwise', function (int|float $percent, string $written): void {
    $percentage = Percentage::parse($percent);

    expect($percentage instanceof Percentage ? $percentage->written() : '')->toBe($written);
})->with([
    [100, '100'],
    [0, '0'],
    [83.41, '83.41'],
    [61.2, '61.2'],
    [7.05, '7.05'],
    [0.5, '0.5'],
]);

it('is what a score or a floor is', function (): void {
    expect(Percentage::of(Score::ofHundredths(6_666)))->toEqual(Percentage::inHundredths(6_666))
        ->and(Percentage::of(Floor::of(83.41)))->toEqual(Percentage::parse(83.41));
});

it('takes a percent in hundredths, truncated, inside 0 to 100 or not', function (): void {
    expect(Percentage::hundredthsOf(83.419))->toBe(8_341)
        ->and(Percentage::hundredthsOf(0.29))->toBe(29)
        ->and(Percentage::hundredthsOf(99.5))->toBe(9_950)
        ->and(Percentage::hundredthsOf(150))->toBe(15_000);
});

it('shows hundredths as points with two decimals', function (int $hundredths, string $points): void {
    expect(Percentage::points($hundredths))->toBe($points);
})->with([
    [8_341, '83.41'],
    [5, '0.05'],
    [10_000, '100.00'],
    [0, '0.00'],
]);

it('says how much of a whole a percent is', function (): void {
    expect(Percentage::fractionOf(100.0))->toBe(1.0)
        ->and(Percentage::fractionOf(25.0))->toBe(0.25)
        ->and(Percentage::fractionOf(0.0))->toBe(0.0);
});
