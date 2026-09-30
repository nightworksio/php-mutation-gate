<?php

declare(strict_types=1);

use Library\Money;

// The runner contract's marks, each left only where its variable names a
// file: CONTRACT_LOADED when this file loads, CONTRACT_WRAPPED when it loads
// through a user-space `file://` wrapper, as a mutant's own run loads it, and
// CONTRACT_RAN when a test of it runs.
if (getenv('CONTRACT_LOADED') !== false) {
    touch((string) getenv('CONTRACT_LOADED'));
}

if (getenv('CONTRACT_WRAPPED') !== false) {
    $self = fopen(__FILE__, 'r');

    if ($self !== false && stream_get_meta_data($self)['wrapper_type'] === 'user-space') {
        touch((string) getenv('CONTRACT_WRAPPED'));
    }
}

// covers() narrows a run unless --path is given, so the drain test in
// DrainSpec still judging Money's loop is what proves the adapter passes it.
covers(Money::class);

it('adds two amounts', function (): void {
    if (getenv('CONTRACT_RAN') !== false) {
        touch((string) getenv('CONTRACT_RAN'));
    }

    // Where LIBRARY_HOG is set, a mutant's own process holds memory until PHP
    // stops it: under the memory_limit LIBRARY_HOG names, or, where it is
    // `cap`, under the one the process runs under.
    $hog = getenv('LIBRARY_HOG');

    if ($hog !== false && getenv('PEST_MUTATION_TESTING') !== false) {
        if ($hog !== 'cap') {
            ini_set('memory_limit', $hog);
        }

        $held = [];

        while (true) {
            $held[] = str_repeat('x', 1024);
        }
    }

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
