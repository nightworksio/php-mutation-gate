<?php

declare(strict_types=1);

use Library\Held;

// Held::double's line is held by this group, whose test cannot tell doubling
// from subtracting: under the group its mutant survives, while DoubleSpec,
// outside the group, would kill it.
it('doubles nothing to nothing', function (): void {
    expect(new Held()->double(0))->toBe(0);
})->group('holds:src/Held.php');
