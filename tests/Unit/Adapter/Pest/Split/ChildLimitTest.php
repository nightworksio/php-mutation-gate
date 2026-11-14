<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit;

it('holds a child to a multiple of the parent\'s own time of the test, never under the floor', function (): void {
    $limit = new ChildLimit(3.0, 2.0, 30.0);

    expect([$limit->of(1.5), $limit->of(0.5), $limit->of(0.0)])->toBe([4.5, 2.0, 2.0]);
});

it('holds a child to the most a test may take where the test\'s own time is not known, or no multiple is set', function (): void {
    expect([
        new ChildLimit(3.0, 2.0, 30.0)->of(own: false),
        new ChildLimit(0.0, 2.0, 30.0)->of(1.5),
        new ChildLimit(-1.0, 2.0, 30.0)->of(1.5),
        new ChildLimit(0.01, 0.0, 30.0)->of(1.0),
    ])->toBe([30.0, 30.0, 30.0, 0.01]);
});

it('holds a counted child to its fork\'s steps, the multiple of what its parent took after the fork, and the floor, its time to the most', function (): void {
    $limit = new ChildLimit(3.0, 2.0, 30.0, 1000);

    expect([$limit->stepsOf(500, 700), $limit->stepsOf(500, 500), $limit->stepsOf(10, 11)])->toBe([2100, 1500, 1013])
        ->and($limit->longest())->toBe(30.0)
        ->and([$limit->countsSteps(), new ChildLimit(3.0, 2.0, 30.0)->countsSteps(), new ChildLimit(0.0, 2.0, 30.0, 1000)->countsSteps()])
        ->toBe([true, false, false]);
});
