<?php

declare(strict_types=1);

use Library\Held;

it('doubles an amount', function (): void {
    expect(new Held()->double(4))->toBe(8);
});
