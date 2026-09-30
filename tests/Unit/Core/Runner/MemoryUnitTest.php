<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;

it('counts each unit in bytes as PHP does', function (): void {
    expect(array_map(static fn(MemoryUnit $unit): array => [$unit->value, $unit->bytes()], MemoryUnit::cases()))->toBe([
        ['', 1],
        ['K', 1024],
        ['M', 1048576],
        ['G', 1073741824],
    ]);
});
