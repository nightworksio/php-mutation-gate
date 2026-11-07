<?php

declare(strict_types=1);

use Library\Split;

// Its set-up is quick alone. Under mutation, after another file's set-up in one
// process, it outlasts any limit here, which no test's own time holds.
beforeAll(function (): void {
    $GLOBALS['splitSetUps'] = ($GLOBALS['splitSetUps'] ?? 0) + 1;

    usleep(getenv('PEST_MUTATION_TESTING') !== false && $GLOBALS['splitSetUps'] > 1 ? 120_000_000 : 100_000);
});

it('counts nothing few', function (): void {
    usleep(200_000);

    expect(new Split()->count(0))->toBe('few');
});
