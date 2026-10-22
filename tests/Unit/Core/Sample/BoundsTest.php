<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Sample\Bounds;
use NightWorksIO\MutationGate\Core\Sample\Confidence;

it('holds one share with nothing either side where every mutant was counted', function (): void {
    expect(Bounds::exactly(0.4)->lower())->toBe(0.4)
        ->and(Bounds::exactly(0.4)->upper())->toBe(0.4);
});

it('allows every share where nothing was counted', function (): void {
    expect(Bounds::anything()->lower())->toBe(0.0)
        ->and(Bounds::anything()->upper())->toBe(1.0);
});

it('is Wilson\'s score interval about the share, as wide as its confidence asks', function (Confidence $confidence, float $lower, float $upper): void {
    $bounds = Bounds::wilson(0.5, 100.0, $confidence);

    expect($bounds->lower())->toEqualWithDelta($lower, 1e-12)
        ->and($bounds->upper())->toEqualWithDelta($upper, 1e-12);
})->with([
    '90 percent' => [Confidence::Ninety, 0.41884779611252954, 0.5811522038874705],
    '95 percent' => [Confidence::NinetyFive, 0.4038315303659956, 0.5961684696340044],
    '99 percent' => [Confidence::NinetyNine, 0.37527962504483986, 0.6247203749551602],
]);

it('keeps the interval between none and all of the mutants', function (): void {
    expect(Bounds::wilson(1.0, 20.0, Confidence::NinetyFive)->upper())->toBe(1.0)
        ->and(Bounds::wilson(1.0, 20.0, Confidence::NinetyFive)->lower())->toEqualWithDelta(0.8388748419471806, 1e-12)
        ->and(Bounds::wilson(0.0, 20.0, Confidence::NinetyFive)->lower())->toBe(0.0)
        ->and(Bounds::wilson(0.0, 20.0, Confidence::NinetyFive)->upper())->toEqualWithDelta(0.16112515805281938, 1e-12);
});
