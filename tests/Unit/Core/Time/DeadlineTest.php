<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;

$makeStarted = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-09-30T10:00:00.250000Z');

it('leaves the budget less what has passed, and nothing once it has passed', function () use ($makeStarted): void {
    $started = $makeStarted();

    $deadline = Deadline::after($started, Seconds::of(90.0));

    expect($deadline->left($started))->toEqual(Seconds::of(90.0))
        ->and($deadline->left(new DateTimeImmutable('2026-09-30T10:01:00.750000Z')))->toEqual(Seconds::of(29.5))
        ->and($deadline->left(new DateTimeImmutable('2026-09-30T10:05:00Z')))->toEqual(Seconds::of(0.0));
});

it('falls at the moment a wall clock reads as so many seconds since the epoch', function (): void {
    $deadline = Deadline::atEpoch(100.5);

    expect($deadline->left(new DateTimeImmutable('@40')))->toEqual(Seconds::of(60.5))
        ->and($deadline->hasPassed(new DateTimeImmutable('@101')))->toBeTrue();
});

it('says whether it has passed', function () use ($makeStarted): void {
    $started = $makeStarted();

    $deadline = Deadline::after($started, Seconds::of(90.0));

    expect($deadline->hasPassed(new DateTimeImmutable('2026-09-30T10:01:30.000000Z')))->toBeFalse()
        ->and($deadline->hasPassed(new DateTimeImmutable('2026-09-30T10:01:30.250000Z')))->toBeTrue()
        ->and($deadline->hasPassed(new DateTimeImmutable('2026-09-30T10:02:00Z')))->toBeTrue();
});

it('fits as many runs as their whole length allows in the time left, every one that takes no time, and none once it has passed', function () use ($makeStarted): void {
    $started = $makeStarted();

    $deadline = Deadline::after($started, Seconds::of(90.0));

    expect($deadline->fitting(5, Seconds::of(20.0), $started))->toBe(4)
        ->and($deadline->fitting(3, Seconds::of(20.0), $started))->toBe(3)
        ->and($deadline->fitting(5, Seconds::of(30.0), $started))->toBe(3)
        ->and($deadline->fitting(5, Seconds::of(20.0), new DateTimeImmutable('2026-09-30T10:01:20.250000Z')))->toBe(0)
        ->and($deadline->fitting(500, Seconds::of(0.5), $started))->toBe(180)
        ->and($deadline->fitting(5, Seconds::of(0.0), $started))->toBe(5)
        ->and($deadline->fitting(5, Seconds::of(0.0), new DateTimeImmutable('2026-09-30T10:05:00Z')))->toBe(0);
});

it('fits as many rounds of runs side by side as their whole length allows in the time left', function () use ($makeStarted): void {
    $started = $makeStarted();

    $deadline = Deadline::after($started, Seconds::of(90.0));

    expect($deadline->fitting(9, Seconds::of(40.0), $started, 3))->toBe(6)
        ->and($deadline->fitting(5, Seconds::of(40.0), $started, 3))->toBe(5)
        ->and($deadline->fitting(9, Seconds::of(100.0), $started, 3))->toBe(0)
        ->and($deadline->fitting(9, Seconds::of(40.0), new DateTimeImmutable('2026-09-30T10:01:30.250000Z'), 3))->toBe(0);
});

it('fits rounds of runs side by side in a length of time as it fits them in the time left', function (): void {
    expect(Deadline::fittingIn(Seconds::of(90.0), 9, Seconds::of(40.0), 3))->toBe(6)
        ->and(Deadline::fittingIn(Seconds::of(90.0), 5, Seconds::of(20.0)))->toBe(4)
        ->and(Deadline::fittingIn(Seconds::of(90.0), 5, Seconds::of(0.0)))->toBe(5)
        ->and(Deadline::fittingIn(Seconds::of(0.0), 5, Seconds::of(0.0)))->toBe(0)
        ->and(Deadline::fittingIn(Seconds::of(-5.0), 5, Seconds::of(1.0), 3))->toBe(0);
});

it('fits each run by its own length, side by side, passing over one too long for the time left and still fitting the shorter ones after it', function (array $lengths, int $abreast, array $fits) use ($makeStarted): void {
    $started = $makeStarted();
    $deadline = Deadline::after($started, Seconds::of(90.0));
    $each = [];

    foreach ($lengths as $length) {
        $each[] = Seconds::of(is_numeric($length) ? (float) $length : 0.0);
    }

    expect($deadline->fittingEach($each, $started, max(1, $abreast)))->toBe($fits)
        ->and(Deadline::fittingEachIn(Seconds::of(90.0), $each, max(1, $abreast)))->toBe($fits);
})->with([
    'one after another, until the time is full' => [[40.0, 40.0, 40.0], 1, [0, 1]],
    'a long one first keeps no short one from running' => [[300.0, 10.0, 20.0], 1, [1, 2]],
    'a long one in the middle is passed over' => [[50.0, 300.0, 40.0], 1, [0, 2]],
    'side by side, each where the first is free' => [[80.0, 30.0, 50.0, 20.0], 2, [0, 1, 2]],
    'one that ends exactly at the deadline fits' => [[90.0, 90.0], 2, [0, 1]],
    'one a moment too long does not' => [[90.5], 1, []],
    'runs that take no time all fit' => [[0.0, 0.0, 0.0], 1, [0, 1, 2]],
    'none asked' => [[], 3, []],
]);

it('fits no run, of whatever length, once the deadline has passed or where there is no time', function () use ($makeStarted): void {
    $deadline = Deadline::after($makeStarted(), Seconds::of(90.0));
    $each = [Seconds::of(0.0), Seconds::of(5.0)];

    expect($deadline->fittingEach($each, new DateTimeImmutable('2026-09-30T10:05:00Z')))->toBe([])
        ->and(Deadline::fittingEachIn(Seconds::of(0.0), $each))->toBe([])
        ->and(Deadline::fittingEachIn(Seconds::of(-1.0), $each, 4))->toBe([]);
});

it('fits each run one after another where it is told no number side by side, and a run of no length in whatever time is left', function () use ($makeStarted): void {
    $started = $makeStarted();
    $deadline = Deadline::after($started, Seconds::of(90.0));
    $each = [Seconds::of(40.0), Seconds::of(40.0), Seconds::of(40.0)];

    expect($deadline->fittingEach($each, $started))->toBe([0, 1])
        ->and(Deadline::fittingEachIn(Seconds::of(90.0), $each))->toBe([0, 1])
        ->and(Deadline::fittingEachIn(Seconds::of(0.5), [Seconds::of(0.0), Seconds::of(0.25), Seconds::of(0.5)]))->toBe([0, 1])
        ->and(Deadline::fittingEachIn(Seconds::of(0.5), [Seconds::of(0.25), Seconds::of(0.25)], 2))->toBe([0, 1]);
});

it('says how long past the deadline a moment is, and none before it or at it', function () use ($makeStarted): void {
    $deadline = Deadline::after($makeStarted(), Seconds::of(90.0));

    expect($deadline->overrunAt(new DateTimeImmutable('2026-09-30T10:02:00.250000Z')))->toBe(30.0)
        ->and($deadline->overrunAt(new DateTimeImmutable('2026-09-30T10:01:30.250000Z')))->toBe(0.0)
        ->and($deadline->overrunAt(new DateTimeImmutable('2026-09-30T10:01:29.750000Z')))->toBe(0.0)
        ->and($deadline->overrunAt(new DateTimeImmutable('2026-09-30T10:01:30.750000Z')))->toBe(0.5);
});
