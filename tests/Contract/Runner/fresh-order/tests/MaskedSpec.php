<?php

declare(strict_types=1);

require_once sprintf('%s/src/Masked.php', dirname(__DIR__));

use Library\Masked;

it('doubles what it was primed with, slowly', function (): void {
    usleep(2_000_000);
    Masked::prime(4);

    expect(Masked::doubled(2))->toBe(4);
});

it('doubles two', function (): void {
    expect(Masked::doubled(2))->toBe(4);
});
