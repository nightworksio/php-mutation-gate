<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;

it('holds its number', function (): void {
    expect(Line::of(42)->number())->toBe(42);
});
