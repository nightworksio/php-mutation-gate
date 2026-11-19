<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\PartCosts;

it('costs a test its map time once for its parent and once more for each mutant the pass forks at it, and an untimed test nothing', function (): void {
    $forks = PartCosts::forksOf(['m1', 'm2'], ['m1' => ['a', 'b'], 'm2' => ['b'], 'm3' => ['c']]);

    expect($forks)->toBe(['a' => 1, 'b' => 2])
        ->and(PartCosts::of(['a' => 1.0, 'b' => 0.5, 'c' => 2.0], $forks)->byTest())->toBe(['a' => 2.0, 'b' => 1.5, 'c' => 2.0]);
});

it('orders the parts costliest first, parts of equal cost in their order', function (): void {
    $costs = PartCosts::of(['a' => 1.0, 'b' => 3.0, 'c' => 1.0, 'd' => 0.5], []);

    expect($costs->costliestFirst([
        ['files' => ['A.php'], 'tests' => ['a']],
        ['files' => ['B.php'], 'tests' => ['b']],
        ['files' => ['C.php'], 'tests' => ['c', 'untimed']],
        ['files' => ['D.php'], 'tests' => ['d', 'a']],
    ]))->toBe([1, 3, 0, 2]);
});
