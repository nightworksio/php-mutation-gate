<?php

declare(strict_types=1);

use Library\Unexecutable\Level;
use Library\Unexecutable\Other;
use Library\Unexecutable\Rates as Priced;

// Each value on a line coverage cannot see run, read by a test the reference
// scan finds, which asserts the value exactly and so kills its mutant.

it('reads a constant through an alias', function (): void {
    expect(Priced::ALIASED)->toBe(7);
});

it('runs a method that reads a constant through self', function (): void {
    expect(new Priced()->internal())->toBe(11);
});

it('reads an inherited and an interface constant through the class', function (): void {
    expect(Priced::INHERITED)->toBe(5)
        ->and(Priced::LEVEL)->toBe(3);
});

it('reads a static and an instance property\'s default', function (): void {
    expect(Priced::$count)->toBe(13)
        ->and(new Priced()->cents)->toBe(17);
});

it('reads an enum case\'s value, and another\'s only through from()', function (): void {
    expect(Level::Low->value)->toBe(1)
        ->and(Level::from(2))->toBe(Level::High);
});

it('reads a constant of the same name in another class', function (): void {
    expect(Other::UNREAD)->toBe(29);
});
