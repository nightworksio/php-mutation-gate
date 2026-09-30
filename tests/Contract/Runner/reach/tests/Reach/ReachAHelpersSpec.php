<?php

declare(strict_types=1);

function reachAmount(): int
{
    return 5;
}

it('declares the helper another test file uses', function (): void {
    expect(reachAmount())->toBe(5);
});
