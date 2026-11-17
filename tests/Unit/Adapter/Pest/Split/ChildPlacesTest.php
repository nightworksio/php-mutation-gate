<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildPlaces;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('holds a fixed count of children, at least one, and marks no stream running', function (): void {
    $directory = Scratch::directory();
    ChildPlaces::fixed(3)->joined();

    expect(ChildPlaces::fixed(3)->now())->toBe(3)
        ->and(ChildPlaces::fixed(3)->most())->toBe(3)
        ->and(ChildPlaces::sharing(4, $directory)->most())->toBe(4)
        ->and(ChildPlaces::fixed(0)->now())->toBe(1)
        ->and(glob(sprintf('%s/*', $directory)))->toBe([]);
});

it('shares the cores among the streams marked running, at least one a stream', function (): void {
    $directory = Scratch::directory();
    $places = ChildPlaces::sharing(4, $directory);

    expect($places->now())->toBe(4);

    $places->joined();

    expect(glob(sprintf('%s/live-*', $directory)))->toBe([sprintf('%s/live-%d', $directory, getmypid())])
        ->and($places->now())->toBe(4);

    touch(sprintf('%s/live-1', $directory));

    expect($places->now())->toBe(2);

    foreach (range(2, 8) as $pid) {
        touch(sprintf('%s/live-%d', $directory, $pid));
    }

    expect($places->now())->toBe(1)
        ->and(ChildPlaces::sharing(0, $directory)->now())->toBe(1);
});
