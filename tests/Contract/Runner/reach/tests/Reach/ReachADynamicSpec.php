<?php

declare(strict_types=1);

function reachNamed(): int
{
    return 5;
}

it('declares the helper another test file calls by a name it holds', function (): void {
    expect(reachNamed())->toBe(5);
});
