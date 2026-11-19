<?php

declare(strict_types=1);

use Library\Stalled;

it('stalls in any split stream that runs it', function (): void {
    new Stalled()->raised(8);

    if (getenv('MUTATION_GATE_SPLIT_PLAN') !== false) {
        sleep(90);
    }

    expect(true)->toBeTrue();
});

it('raises a small amount', function (): void {
    expect(new Stalled()->raised(8))->toBe(10);
});
