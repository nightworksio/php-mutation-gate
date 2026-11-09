<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Comment\Key;

it('names the enum each key takes words of by its short name, as a PHP config imports it', function (): void {
    expect(array_map(static fn(Key $key): string => $key->enum(), Key::cases()))
        ->toBe(['Section', 'Section', 'State', 'Occasion', 'Lines', 'Detail', 'Mode', 'Passing']);
});
