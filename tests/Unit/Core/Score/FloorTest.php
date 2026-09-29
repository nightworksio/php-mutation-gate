<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NotAPercentage;

it('holds a floor in hundredths, truncated rather than rounded', function (float $percent, int $hundredths): void {
    expect(Floor::of($percent)->hundredths())->toBe($hundredths);
})->with([
    'two decimals' => [83.41, 8341],
    'more decimals' => [83.419, 8341],
    'binary noise below' => [0.29, 29],
    'whole' => [100, 10_000],
    'none' => [0, 0],
]);

it('gives back the percentage it holds', function (): void {
    expect(Floor::of(61.2)->percent())->toBe(61.2)
        ->and(Floor::ofHundredths(8341)->percent())->toBe(83.41);
});

it('takes a floor in hundredths from 0 to 10000', function (int $hundredths): void {
    expect(Floor::ofHundredths($hundredths)->hundredths())->toBe($hundredths);
})->with([0, 1, 9_999, 10_000]);

it('refuses a floor outside 0 to 100', function (int $hundredths, string $message): void {
    expect(static fn(): Floor => Floor::ofHundredths($hundredths))->toThrow(NotAPercentage::class, $message);
})->with([
    'below' => [-1, '-0.01 is not a percentage from 0 to 100.'],
    'above' => [10_001, '100.01 is not a percentage from 0 to 100.'],
]);

it('refuses a percentage outside 0 to 100', function (): void {
    expect(static fn(): Floor => Floor::of(100.01))->toThrow(NotAPercentage::class, '100.01 is not a percentage from 0 to 100.');
});
