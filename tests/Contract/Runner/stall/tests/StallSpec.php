<?php

declare(strict_types=1);

use Library\Stall;

// Each test takes most of a second, so the silence limit of the slowest
// falls seconds short of the limit of all three.
it('drains a first amount', function (): void {
    usleep(800_000);

    expect(new Stall()->drain(1))->toBe(0);
});

it('drains a second amount', function (): void {
    usleep(800_000);

    expect(new Stall()->drain(2))->toBe(0);
});

it('drains a third amount', function (): void {
    usleep(800_000);

    expect(new Stall()->drain(3))->toBe(0);
});
