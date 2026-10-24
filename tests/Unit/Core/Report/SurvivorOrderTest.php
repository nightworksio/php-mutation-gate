<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\SurvivorOrder;

it('lists the survivors on changed lines first, each part by file and then by line, whatever else a survivor holds first', function (): void {
    $survivors = [
        [9, 'b.php', false],
        [2, 'b.php', true],
        [7, 'a.php', false],
        [1, 'c.php', true],
        [3, 'a.php', false],
    ];

    expect(SurvivorOrder::of(
        $survivors,
        static fn(array $survivor): bool => $survivor[2],
        static fn(array $survivor): array => [$survivor[1], $survivor[0]],
    ))->toBe([
        [2, 'b.php', true],
        [1, 'c.php', true],
        [3, 'a.php', false],
        [7, 'a.php', false],
        [9, 'b.php', false],
    ]);
});
