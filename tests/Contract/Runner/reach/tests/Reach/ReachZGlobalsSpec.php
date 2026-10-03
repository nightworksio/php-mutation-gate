<?php

declare(strict_types=1);

use Library\Reach;

it('reads a global another test file sets', function (): void {
    expect($GLOBALS['reachShared'] ?? null)->toBe(5)
        ->and(Reach::amount(5))->toBeInt();
});
