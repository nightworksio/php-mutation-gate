<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Ordered.php';

use Library\Ordered;

it('adds after a pause', function (): void {
    usleep(400_000);

    expect(Ordered::sum(2, 3))->toBe(5);
});

it('adds', function (): void {
    expect(Ordered::sum(2, 3))->toBe(5);
});
