<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\Role;

it('names what a file is to the tests', function (): void {
    expect(array_map(static fn(Role $role): string => $role->value, Role::cases()))
        ->toBe(['test case', 'support', 'loaded', 'elsewhere']);
});
