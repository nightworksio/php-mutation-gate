<?php

declare(strict_types=1);

use Library\Unexecutable\Chain;

use function Library\Unexecutable\discount;

use Library\Unexecutable\Early;
use Library\Unexecutable\Limits;
use Library\Unexecutable\Scaled;

use const Library\Unexecutable\STARTING;

use function Library\Unexecutable\tax;

use Library\Unexecutable\Weighed;

require_once __DIR__ . '/../src/Unexecutable/helpers.php';

it('calls a function with its parameter left out', function (): void {
    expect(tax())->toBe(21);
});

it('calls a function a test file requires with its parameter left out', function (): void {
    expect(discount())->toBe(10);
});

it('calls a closure with its parameter left out', function (): void {
    expect(new Scaled()->scale())->toBe(3);
});

it('reads a global constant the files autoload declared', function (): void {
    expect(STARTING)->toBe(3);
});

it('reads an attribute\'s argument by reflection', function (): void {
    expect(new Weighed()->weight())->toBe(5);
});

it('reads a constant through a variable class', function (): void {
    expect(Limits::of(Limits::class))->toBe(7);
});

it('reads a value through four declarations', function (): void {
    expect(new Chain()->fifth())->toBe(2);
});

it('reads an enum tests/Pest.php loaded first', function (): void {
    expect(Early::First->value)->toBe(1);
});
