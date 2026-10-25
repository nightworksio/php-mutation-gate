<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;

it('holds its number', function (): void {
    expect(Line::of(42)->number())->toBe(42);
});

it('counts the first line of a file as 1', function (): void {
    expect(Line::first())->toEqual(Line::of(1));
});
