<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\PartCounts;

it('cuts as many parts for each process as the setting asks, the readers\' processes theirs and the rest the others\'', function (): void {
    expect(PartCounts::of(4, 0, 1))->toBe([0, 4])
        ->and(PartCounts::of(4, 1, 1))->toBe([1, 3])
        ->and(PartCounts::of(4, 1, 3))->toBe([3, 9])
        ->and(PartCounts::of(6, 2, 2))->toBe([4, 8]);
});

it('gives the others a process at least, and counts fewer than one part for a process as one', function (): void {
    expect(PartCounts::of(2, 2, 2))->toBe([4, 2])
        ->and(PartCounts::of(1, 3, 2))->toBe([6, 2])
        ->and(PartCounts::of(3, 1, 0))->toBe([1, 2])
        ->and(PartCounts::of(3, 1, -2))->toBe([1, 2]);
});
