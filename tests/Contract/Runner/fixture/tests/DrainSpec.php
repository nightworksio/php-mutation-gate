<?php

declare(strict_types=1);

use Library\Money;

it('drains an amount to nothing', function (): void {
    expect(new Money()->drain(3))->toBe(0);
});
