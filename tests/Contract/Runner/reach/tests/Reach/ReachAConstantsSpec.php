<?php

declare(strict_types=1);

const REACH_AMOUNT = 5;

it('declares the constant another test file uses', function (): void {
    expect(REACH_AMOUNT)->toBe(5);
});
