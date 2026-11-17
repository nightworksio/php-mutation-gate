<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\ForkedChild;

it('holds a child to the most, and lets it out of its place past the map\'s time, until its parent ends the test', function (): void {
    $child = ForkedChild::forked('m1', 't1', 2, 7, 100.0, 30.0, 0.5);

    expect([$child->mutant, $child->test, $child->slot, $child->atFork])->toBe(['m1', 't1', 2, 7])
        ->and([$child->isDue(100.5), $child->isDue(100.6), $child->isDue(130.0), $child->isDue(130.1)])->toBe([false, true, true, false])
        ->and([$child->isLate(130.0), $child->isLate(130.1)])->toBe([false, true]);
});

it('holds a child to the limit its parent\'s time sets from its fork once the parent ended the test, and keeps it in its place', function (): void {
    $child = ForkedChild::forked('m1', 't1', 0, 0, 100.0, 30.0, 0.5)->heldTo(2.0);

    expect([$child->isLate(102.0), $child->isLate(102.1), $child->isDue(101.0)])->toBe([false, true, false]);
});
