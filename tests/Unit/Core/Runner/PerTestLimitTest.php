<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('allows a test three times its own time by default, never under three seconds', function (float $own, float $allowed): void {
    $standard = PerTestLimit::standard();

    expect($standard->allowing(Seconds::of($own)))->toEqual(Seconds::of($allowed))
        ->and($standard->isOn())->toBeTrue()
        ->and($standard->multiple())->toBe(3)
        ->and($standard->floor())->toEqual(Seconds::of(3.0));
})->with([
    'a fast test, held to the floor' => [0.2, 3.0],
    'a test at the floor exactly' => [1.0, 3.0],
    'a slow test, held to its multiple' => [2.5, 7.5],
]);

it('takes its own multiple and floor, and is off at a multiple of none or below', function (): void {
    expect(PerTestLimit::of(5, Seconds::of(2.0))->allowing(Seconds::of(1.0)))->toEqual(Seconds::of(5.0))
        ->and(PerTestLimit::of(0, Seconds::of(2.0))->isOn())->toBeFalse()
        ->and(PerTestLimit::of(-2, Seconds::of(2.0))->multiple())->toBe(0)
        ->and(PerTestLimit::of(1, Seconds::of(2.0))->isOn())->toBeTrue()
        ->and(PerTestLimit::off()->isOn())->toBeFalse()
        ->and(PerTestLimit::off()->multiple())->toBe(0);
});
