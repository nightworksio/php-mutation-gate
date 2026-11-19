<?php

declare(strict_types=1);

use Library\Waited;

it('doubles nothing into nothing', function (): void {
    expect(new Waited()->doubled(0))->toBe(0);
});
