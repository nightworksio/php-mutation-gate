<?php

declare(strict_types=1);

use Library\Measured;

it('doubles a small amount where pcov is on', function (): void {
    expect(ini_get('pcov.enabled'))->toBe('1')
        ->and(new Measured()->doubled(2))->toBe(4);
});
