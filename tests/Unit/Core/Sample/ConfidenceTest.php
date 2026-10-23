<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Sample\Confidence;

it('is 90, 95 or 99 percent', function (): void {
    expect(array_map(static fn(Confidence $confidence): int => $confidence->value, Confidence::cases()))->toBe([90, 95, 99]);
});

it('spans as many standard errors each side as a two-sided interval of its confidence does', function (Confidence $confidence, float $quantile): void {
    expect($confidence->quantile())->toBe($quantile);
})->with([
    '90 percent' => [Confidence::Ninety, 1.6448536269514722],
    '95 percent' => [Confidence::NinetyFive, 1.959963984540054],
    '99 percent' => [Confidence::NinetyNine, 2.5758293035489004],
]);
