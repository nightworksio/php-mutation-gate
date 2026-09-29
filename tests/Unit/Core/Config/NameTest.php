<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Name;

it('holds the name a config chooses by', function (): void {
    expect(Name::of('sarif')->value())->toBe('sarif');
});
