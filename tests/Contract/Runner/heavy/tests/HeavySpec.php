<?php

declare(strict_types=1);

use Library\Heavy;

// Each test takes most of two seconds, so the four take longer than the most
// a mutant's run is allowed, and one alone fits in it. Only the slowest,
// which runs last, tells a kilogram from more.
it('weighs a letter light', function (): void {
    usleep(1_700_000);

    expect(new Heavy()->weigh(5))->toBe('light');
});

it('weighs a crate heavy', function (): void {
    usleep(1_700_000);

    expect(new Heavy()->weigh(2000))->toBe('heavy');
});

it('weighs nothing light', function (): void {
    usleep(1_700_000);

    expect(new Heavy()->weigh(0))->toBe('light');
});

it('weighs a kilogram light', function (): void {
    usleep(1_800_000);

    expect(new Heavy()->weigh(1000))->toBe('light');
});
