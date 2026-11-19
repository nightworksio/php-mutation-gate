<?php

declare(strict_types=1);

use Library\Waited;

it('doubles two into four, slowly', function (): void {
    usleep(300_000);

    if (getenv('LIBRARY_STALL') !== false && getenv('MUTATION_GATE_SPLIT_PLAN') !== false) {
        sleep(60);
    }

    expect(new Waited()->doubled(2))->toBe(4);
});
