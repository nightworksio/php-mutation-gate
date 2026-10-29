<?php

declare(strict_types=1);

use Library\Hang;

it('counts down quietly', function (): void {
    expect(new Hang()->quietly(2))->toBe(0);
});

it('counts down loudly', function (): void {
    expect(new Hang()->loudly(2))->toBe(0);
});

it('sums', function (): void {
    expect(new Hang()->sum(2, 3))->toBe(5);
});
