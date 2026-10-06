<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Workers;

it('holds how many processes run side by side and how each starts', function (): void {
    $pool = Pool::of(ProcessCount::of(4), Workers::Fork);

    expect([$pool->processes()->count(), $pool->workers()])->toBe([4, Workers::Fork]);
});

it('is one fresh process where nothing asks for more', function (): void {
    expect([Pool::single()->processes()->count(), Pool::single()->workers()])->toBe([1, Workers::Fresh]);
});
