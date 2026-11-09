<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ProcessCount;

it('holds how many processes may run side by side', function (): void {
    expect(ProcessCount::of(8)->count())->toBe(8);
});

it('is one process where nothing asks for more', function (): void {
    expect(ProcessCount::single()->count())->toBe(1);
});

it('is as many processes as a count, and one where the count comes to none', function (): void {
    expect([ProcessCount::atLeastOne(3)->count(), ProcessCount::atLeastOne(1)->count(), ProcessCount::atLeastOne(0)->count(), ProcessCount::atLeastOne(-2)->count()])
        ->toBe([3, 1, 1, 1]);
});
