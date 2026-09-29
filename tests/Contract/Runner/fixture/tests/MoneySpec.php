<?php

declare(strict_types=1);

use Library\Money;

// covers() narrows a run unless --path is given, so the drain test in
// DrainSpec still judging Money's loop is what proves the adapter passes it.
covers(Money::class);

it('adds two amounts', function (): void {
    expect(new Money()->add(2, 3))->toBe(5);
})->group('mutation-canary');

it('tells a large amount from a small one', function (): void {
    expect(new Money()->isLarge(500))->toBeTrue()
        ->and(new Money()->isLarge(1))->toBeFalse();
});
