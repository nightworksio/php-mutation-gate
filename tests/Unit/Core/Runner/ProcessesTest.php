<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Processes;

it('holds how many processes may run side by side', function (): void {
    expect(Processes::of(8)->count())->toBe(8);
});

it('is one process where nothing asks for more', function (): void {
    expect(Processes::single()->count())->toBe(1);
});
