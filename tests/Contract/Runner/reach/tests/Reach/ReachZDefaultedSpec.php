<?php

declare(strict_types=1);

use Library\Reach;

it('falls back to a value of its own where a hook another test file registers sets none', function (): void {
    expect(Reach::amount($this->reached ?? 4))->toBeGreaterThan(4);
});
