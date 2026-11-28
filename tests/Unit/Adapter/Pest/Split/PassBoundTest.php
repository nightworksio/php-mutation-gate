<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\PassBound;

it('bounds a pass by its mutants\' covering tests\' seconds over the processes', function (): void {
    $times = ['T::one' => 2.0, 'T::two' => 4.0];
    $two = ['a' => ['T::one', 'T::two'], 'b' => ['T::two']];

    expect(PassBound::of([], $times, 2))->toBe(0.0)
        ->and(PassBound::of(['a' => ['T::one']], $times, 1))->toBe(2.0)
        ->and(PassBound::of($two, $times, 1))->toBe(10.0)
        ->and(PassBound::of($two, $times, 2))->toBe(5.0)
        ->and(PassBound::of($two, $times, 4))->toBe(2.5);
});

it('adds none for a test timed nowhere, and counts fewer than one process as one', function (): void {
    $times = ['T::one' => 2.0, 'T::two' => 4.0];

    expect(PassBound::of(['a' => ['T::one', 'T::three']], $times, 1))->toBe(2.0)
        ->and(PassBound::of(['a' => ['T::two']], $times, 0))->toBe(4.0)
        ->and(PassBound::of(['a' => ['T::two']], $times, -3))->toBe(4.0);
});
