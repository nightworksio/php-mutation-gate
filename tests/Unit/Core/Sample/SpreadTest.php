<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Sample\Spread;
use NightWorksIO\MutationGate\Core\Sample\Tally;

it('reads nothing from no unit', function (): void {
    $spread = Spread::of();

    expect($spread->units())->toBe(0)
        ->and($spread->sum()->counted())->toBe(0)
        ->and($spread->ratio())->toBe(0.0)
        ->and($spread->meanCounted())->toBe(0.0)
        ->and($spread->variance())->toBe(0.0);
});

it('sums its units, and reads their share killed as killed over counted', function (): void {
    $spread = Spread::of(Tally::of(8, 10), Tally::of(5, 10), Tally::of(9, 10));

    expect($spread->units())->toBe(3)
        ->and($spread->sum()->killed())->toBe(22)
        ->and($spread->sum()->counted())->toBe(30)
        ->and($spread->ratio())->toEqualWithDelta(22 / 30, 1e-12)
        ->and($spread->meanCounted())->toEqualWithDelta(10.0, 1e-12)
        ->and(Spread::of(Tally::of(1, 1))->ratio())->toBe(1.0);
});

it('reads how far each unit lies from what the share gives it, with a degree spent on the share', function (): void {
    expect(Spread::of(Tally::of(8, 10), Tally::of(5, 10), Tally::of(9, 10))->variance())->toEqualWithDelta(13 / 3, 1e-12)
        ->and(Spread::of(Tally::of(1, 2), Tally::of(3, 4))->variance())->toEqualWithDelta(2 / 9, 1e-12);
});

it('has no variance with one unit, or with nothing counted', function (): void {
    expect(Spread::of(Tally::of(3, 4))->variance())->toBe(0.0)
        ->and(Spread::of(Tally::of(3, 4))->meanCounted())->toEqualWithDelta(4.0, 1e-12)
        ->and(Spread::of(Tally::of(0, 0), Tally::of(0, 0))->variance())->toBe(0.0)
        ->and(Spread::of(Tally::of(0, 0), Tally::of(0, 0))->ratio())->toBe(0.0);
});
