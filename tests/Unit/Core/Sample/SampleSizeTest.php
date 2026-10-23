<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Core\Sample\SampleSize;
use NightWorksIO\MutationGate\Core\Sample\Spread;
use NightWorksIO\MutationGate\Core\Sample\Tally;

$last = static fn(): Spread => Spread::of(Tally::of(8, 10), Tally::of(5, 10), Tally::of(9, 10));

it('sizes the sample from the variance between the units\' last results, corrected for the stratum\'s size', function () use ($last): void {
    expect(SampleSize::of(2.0, Confidence::NinetyFive, 3)->units(1_000, 1, 2, $last()))->toBe(95)
        ->and(SampleSize::of(2.0, Confidence::NinetyFive, 3)->units(50, 1, 2, $last()))->toBe(34);
});

it('runs at least as many units as independent mutants would need', function (): void {
    expect(SampleSize::of(2.0, Confidence::NinetyFive, 3)->units(1_000, 1, 2, Spread::of(Tally::of(5, 10), Tally::of(5, 10))))->toBe(57);
});

it('runs at least the fewest units it is given, and at most every unit', function () use ($last): void {
    expect(SampleSize::of(2.0, Confidence::NinetyFive, 200)->units(1_000, 1, 2, $last()))->toBe(200)
        ->and(SampleSize::of(2.0, Confidence::NinetyFive, 200)->units(150, 1, 2, $last()))->toBe(150);
});

it('runs every unit where fewer than two last results say how the units vary', function (): void {
    $size = SampleSize::of(2.0, Confidence::NinetyFive, 3);

    expect($size->units(1_000, 1, 2, Spread::of(Tally::of(8, 10))))->toBe(1_000)
        ->and($size->units(1_000, 1, 2, Spread::of(Tally::of(0, 0), Tally::of(0, 0))))->toBe(1_000);
});

it('sizes a stratum holding a single mutant as it sizes any other', function () use ($last): void {
    expect(SampleSize::of(2.0, Confidence::NinetyFive, 3)->units(1_000, 1, 1, $last()))->toBe(294);
});

it('runs only the fewest units where the stratum holds no mutant', function () use ($last): void {
    expect(SampleSize::of(2.0, Confidence::NinetyFive, 3)->units(1_000, 0, 10, $last()))->toBe(3)
        ->and(SampleSize::of(2.0, Confidence::NinetyFive, 3)->units(2, 0, 10, $last()))->toBe(2);
});

it('runs more units for a narrower margin or a higher confidence, and fewer for a smaller share of the tree', function () use ($last): void {
    $standard = SampleSize::of(2.0, Confidence::NinetyFive, 1)->units(100_000, 1, 2, $last());

    expect(SampleSize::of(1.0, Confidence::NinetyFive, 1)->units(100_000, 1, 2, $last()))->toBeGreaterThan($standard)
        ->and(SampleSize::of(2.0, Confidence::NinetyNine, 1)->units(100_000, 1, 2, $last()))->toBeGreaterThan($standard)
        ->and(SampleSize::of(2.0, Confidence::NinetyFive, 1)->units(100_000, 1, 4, $last()))->toBeLessThan($standard);
});
