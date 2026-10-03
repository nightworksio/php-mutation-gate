<?php

declare(strict_types=1);

define('REACH_DEFINED', 5);

it('defines the constant another test file uses', function (): void {
    expect(REACH_DEFINED)->toBe(5);
});
