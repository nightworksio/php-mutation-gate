<?php

declare(strict_types=1);

use Library\Reach;

it('uses a constant another test file defines', function (): void {
    expect(Reach::amount(REACH_DEFINED))->toBeInt();
});
