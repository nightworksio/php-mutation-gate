<?php

declare(strict_types=1);

use Beside\Ledger;

it('balances what came in against what went out', function (): void {
    expect(new Ledger()->balance(5, 3))->toBe(2);
});
