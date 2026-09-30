<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hold\Standing;

it('names each place a #[Holds] can stand', function (): void {
    expect(array_map(static fn(Standing $standing): string => $standing->value, Standing::cases()))->toBe([
        'test closure',
        'describe closure',
        'hook closure',
        'dataset closure',
        'kept closure',
        'other closure',
        'named function',
        'test class',
        'test method',
        'elsewhere',
    ]);
});
