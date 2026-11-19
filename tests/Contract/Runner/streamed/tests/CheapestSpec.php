<?php

declare(strict_types=1);

use Library\Cheapest;

it('doubles an amount, triples nothing and quadruples one, slowly', function (): void {
    usleep(300_000);

    expect(new Cheapest()->doubled(4))->toBe(8)
        ->and(new Cheapest()->tripled(0))->toBe(0)
        ->and(new Cheapest()->quadrupled(1))->toBe(4);
});

it('doubles and triples into whole numbers, and quadruples two', function (): void {
    expect(new Cheapest()->doubled(2))->toBeInt()
        ->and(new Cheapest()->tripled(3))->toBeInt()
        ->and(new Cheapest()->quadrupled(2))->toBe(8);
});
