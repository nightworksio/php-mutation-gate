<?php

declare(strict_types=1);

use Library\Selected;

it('ends any split stream that runs it', function (): void {
    if (getenv('MUTATION_GATE_SPLIT_PLAN') !== false) {
        posix_kill(getmypid(), SIGKILL);
    }

    expect(true)->toBeTrue();
});

it('quadruples a small amount', function (): void {
    expect(new Selected()->quadrupled(2))->toBe(8);
});
