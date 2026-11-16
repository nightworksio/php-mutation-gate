<?php

declare(strict_types=1);

use Library\Booted;

beforeEach(function (): void {
    $base = getenv('APP_BASE_PATH');

    if (is_string($base) && $base !== '') {
        require sprintf('%s/bootstrap/app.php', $base);
    }
});

it('readies the services', function (): void {
    expect(new Booted()->readied(1))->toBe(2);
});
