<?php

declare(strict_types=1);

use Library\Reach;

it('reads a variable another test file sets', function (): void {
    expect($_ENV['REACH_SHARED'] ?? null)->toBe('5')
        ->and(Reach::amount(5))->toBeInt();
});
