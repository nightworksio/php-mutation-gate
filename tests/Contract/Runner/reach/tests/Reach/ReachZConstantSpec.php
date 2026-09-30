<?php

declare(strict_types=1);

use Library\Reach;

it('uses a constant another test file declares', function (): void {
    expect(Reach::amount(REACH_AMOUNT))->toBeInt();
});
