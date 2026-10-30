<?php

declare(strict_types=1);

use Library\Split;

// Its set-up takes two seconds that no test's own time holds, and its test
// alone tells three from more.
beforeAll(function (): void {
    usleep(2_000_000);
});

it('counts three many', function (): void {
    usleep(200_000);

    expect(new Split()->count(3))->toBe('many');
});
