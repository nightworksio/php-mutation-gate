<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\PHPStan\Rules\LongLines;

it('finds each line longer than 120 characters, by its number, with its length', function (): void {
    $code = implode("\n", [str_repeat('a', 120), str_repeat('b', 121), '', str_repeat('c', 132)]);

    expect(LongLines::in($code))->toBe([2 => 121, 4 => 132]);
});

it('splits lines at every line ending', function (): void {
    $code = sprintf("%s\r\n%s\r%s", str_repeat('a', 121), str_repeat('b', 121), str_repeat('c', 121));

    expect(LongLines::in($code))->toBe([1 => 121, 2 => 121, 3 => 121]);
});

it('counts as Java does: a character outside the basic plane is two', function (): void {
    expect(LongLines::in(str_repeat('—', 120)))->toBe([])
        ->and(LongLines::in(sprintf('%s😀', str_repeat('a', 119))))->toBe([1 => 121]);
});
