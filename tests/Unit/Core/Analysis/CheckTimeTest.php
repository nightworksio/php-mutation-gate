<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\CheckTime;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('adds up its checks and their seconds, and gives the time of one', function (): void {
    $time = CheckTime::none()->with(Seconds::of(0.5))->with(Seconds::of(1.5));

    expect($time->checks())->toBe(2)
        ->and($time->seconds())->toEqual(Seconds::of(2.0))
        ->and($time->each())->toEqual(Seconds::of(1.0))
        ->and(CheckTime::of(4, Seconds::of(2.0))->each())->toEqual(Seconds::of(0.5));
});

it('has measured no check before its first', function (): void {
    expect(CheckTime::none()->each())->toEqual(Unmeasured::duration())
        ->and(CheckTime::none()->checks())->toBe(0);
});
