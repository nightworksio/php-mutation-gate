<?php

declare(strict_types=1);

use Library\Reach;

it('falls back to a value of its own where a dataset in another test file sets no global', function (): void {
    expect(Reach::amount($GLOBALS['reachDataset'] ?? 4))->toBeGreaterThan(4);
});
