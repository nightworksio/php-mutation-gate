<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\TimeLeft;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;

$makeLeft = static fn(float $budget): TimeLeft => new TimeLeft(
    Deadline::after(new DateTimeImmutable('2026-09-30T10:00:00Z'), Seconds::of($budget)),
    new StoppedClock('2026-09-30T10:00:00Z'),
);

it('fits as many runs as rounds of the processes side by side allow in the time left, and every one without a budget', function () use (
    $makeLeft,
): void {
    $unlimited = new TimeLeft(Unlimited::time(), new StoppedClock('2026-09-30T10:00:00Z'));

    expect($makeLeft(25.0)->fitting(7, Seconds::of(10.0), ProcessCount::of(3)))->toBe(6)
        ->and($makeLeft(25.0)->fitting(7, Seconds::of(10.0), ProcessCount::single()))->toBe(2)
        ->and($unlimited->fitting(7, Seconds::of(10.0), ProcessCount::single()))->toBe(7);
});

it('fits one run where its whole length fits in the time left, however much more is left, and always without a budget', function () use ($makeLeft): void {
    $unlimited = new TimeLeft(Unlimited::time(), new StoppedClock('2026-09-30T10:00:00Z'));

    expect($makeLeft(10.0)->fits(Seconds::of(10.0)))->toBeTrue()
        ->and($makeLeft(100.0)->fits(Seconds::of(10.0)))->toBeTrue()
        ->and($makeLeft(9.5)->fits(Seconds::of(10.0)))->toBeFalse()
        ->and($unlimited->fits(Seconds::of(10.0)))->toBeTrue();
});
