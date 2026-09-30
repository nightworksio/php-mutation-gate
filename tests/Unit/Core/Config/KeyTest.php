<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Key;

it('is the key an option is written under', function (): void {
    expect(Key::of('bucket')->value())->toBe('bucket');
});
