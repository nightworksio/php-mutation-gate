<?php

declare(strict_types=1);

use Library\Split;

// The slowest test of its own, which runs first in the mutant's own run.
it('counts five many', function (): void {
    usleep(1_900_000);

    expect(new Split()->count(5))->toBe('many');
});
