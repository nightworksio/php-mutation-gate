<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\SurvivorOrder;

it('lists the survivors on changed lines first, each part by file and then by line', function (): void {
    $survivors = [
        ['b.php', 9, false],
        ['b.php', 2, true],
        ['a.php', 7, false],
        ['c.php', 1, true],
        ['a.php', 3, false],
    ];

    expect(SurvivorOrder::of(
        $survivors,
        static fn(array $survivor): bool => $survivor[2],
        static fn(array $survivor): array => [$survivor[0], $survivor[1]],
    ))->toBe([
        ['b.php', 2, true],
        ['c.php', 1, true],
        ['a.php', 3, false],
        ['a.php', 7, false],
        ['b.php', 9, false],
    ]);
});

