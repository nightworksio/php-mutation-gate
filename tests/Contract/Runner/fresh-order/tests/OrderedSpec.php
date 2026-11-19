<?php

declare(strict_types=1);

require_once sprintf('%s/src/Ordered.php', dirname(__DIR__));

use Library\Ordered;

it('adds first', function (): void {
    expect(Ordered::sum(2, 3))->toBe(5);
});

it('adds', function (): void {
    expect(Ordered::sum(2, 3))->toBe(5);
});
