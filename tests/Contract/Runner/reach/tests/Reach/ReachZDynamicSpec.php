<?php

declare(strict_types=1);

use Library\Reach;

it('calls a helper another test file declares by a name it holds', function (): void {
    $helper = 'reachNamed';

    expect(Reach::amount($helper()))->toBeInt();
});
