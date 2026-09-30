<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;

$started = new DateTimeImmutable('2026-09-30T10:00:00.250000Z');

it('leaves the budget less what has passed, and nothing once it has passed', function () use ($started): void {
    $deadline = Deadline::after($started, Seconds::of(90.0));

    expect($deadline->left($started))->toEqual(Seconds::of(90.0))
        ->and($deadline->left(new DateTimeImmutable('2026-09-30T10:01:00.750000Z')))->toEqual(Seconds::of(29.5))
        ->and($deadline->left(new DateTimeImmutable('2026-09-30T10:05:00Z')))->toEqual(Seconds::of(0.0));
});

it('says whether it has passed', function () use ($started): void {
    $deadline = Deadline::after($started, Seconds::of(90.0));

    expect($deadline->hasPassed(new DateTimeImmutable('2026-09-30T10:01:30.000000Z')))->toBeFalse()
        ->and($deadline->hasPassed(new DateTimeImmutable('2026-09-30T10:01:30.250000Z')))->toBeTrue()
        ->and($deadline->hasPassed(new DateTimeImmutable('2026-09-30T10:02:00Z')))->toBeTrue();
});

it('fits as many runs as their whole length allows in the time left, and every one that takes no time', function () use ($started): void {
    $deadline = Deadline::after($started, Seconds::of(90.0));

    expect($deadline->fitting(5, Seconds::of(20.0), $started))->toBe(4)
        ->and($deadline->fitting(3, Seconds::of(20.0), $started))->toBe(3)
        ->and($deadline->fitting(5, Seconds::of(30.0), $started))->toBe(3)
        ->and($deadline->fitting(5, Seconds::of(20.0), new DateTimeImmutable('2026-09-30T10:01:20.250000Z')))->toBe(0)
        ->and($deadline->fitting(5, Seconds::of(0.0), new DateTimeImmutable('2026-09-30T10:05:00Z')))->toBe(5);
});
