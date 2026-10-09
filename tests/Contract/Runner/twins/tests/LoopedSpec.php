<?php

declare(strict_types=1);

use Library\Looped;

it('returns the item twice', function (): void {
    expect(new Looped()->twice())->toBe(['x', 'x']);
});
