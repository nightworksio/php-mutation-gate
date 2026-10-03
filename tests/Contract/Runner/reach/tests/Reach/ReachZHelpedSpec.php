<?php

declare(strict_types=1);

use Library\Reach;

it('uses a helper another test file declares', function (): void {
    expect(Reach::amount(reachAmount()))->toBeInt();
});
