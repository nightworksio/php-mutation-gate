<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('holds how many processes run side by side and how each starts', function (): void {
    $pool = Pool::of(ProcessCount::of(4), Workers::Fork);

    expect([$pool->processes()->count(), $pool->workers()])->toBe([4, Workers::Fork]);
});

it('is one fresh process where nothing asks for more', function (): void {
    expect([Pool::single()->processes()->count(), Pool::single()->workers()])->toBe([1, Workers::Fresh]);
});

it('lays bounds on the start-up it measured, keeping it when it starts each mutant fresh, and on none where it measured none', function (): void {
    $bounds = LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0));
    $measured = Pool::of(ProcessCount::of(4), Workers::Fork)->startingIn(Seconds::of(2.5));
    $fresh = $measured->fresh();

    expect($measured->bounding($bounds)->startUp())->toEqual(Seconds::of(2.5))
        ->and([$fresh->processes()->count(), $fresh->workers()])->toBe([4, Workers::Fresh])
        ->and($fresh->bounding($bounds)->startUp())->toEqual(Seconds::of(2.5))
        ->and(Pool::single()->bounding($bounds)->startUp())->toBeInstanceOf(Unmeasured::class);
});
