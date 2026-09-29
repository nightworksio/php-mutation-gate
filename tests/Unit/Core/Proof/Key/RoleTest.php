<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Proof\Key\Role;

it('names what a file under the test directories is to a key', function (): void {
    expect(array_map(static fn(Role $role): string => $role->value, Role::cases()))->toBe(['test case', 'support', 'loaded']);
});
