<?php

declare(strict_types=1);

use Library\Crash;

// Made greater than or equal, the check ends the process before any test fails.
it('settles a code of none', function (): void {
    expect(new Crash()->settle(0))->toBe(0);
});
