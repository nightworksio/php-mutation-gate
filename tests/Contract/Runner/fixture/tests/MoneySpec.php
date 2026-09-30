<?php

declare(strict_types=1);

use Library\Money;

// covers() narrows a run unless --path is given, so the drain test in
// DrainSpec still judging Money's loop is what proves the adapter passes it.
covers(Money::class);

it('adds two amounts', function (): void {
    expect(new Money()->add(2, 3))->toBe(5);
})->group('mutation-canary');

// A second test that kills a change to Money::add, after the first, so an
// order that runs it first shows as the killer.
it('adds the same amount to itself', function (): void {
    expect(new Money()->add(4, 4))->toBe(8);
});

it('tells a large amount from a small one', function (): void {
    expect(new Money()->isLarge(500))->toBeTrue()
        ->and(new Money()->isLarge(1))->toBeFalse();
});
