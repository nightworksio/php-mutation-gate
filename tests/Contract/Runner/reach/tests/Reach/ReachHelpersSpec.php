<?php

declare(strict_types=1);

function reachAmount(): int
{
    return 5;
}

it('declares the amount the reach tests use', function (): void {
    expect(reachAmount())->toBe(5);
});
