<?php

declare(strict_types=1);

use Library\Settled;

it('adds one', function (): void {
    expect(new Settled()->added(1))->toBe(2);
});
