<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\JobStop;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\JobTail;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('stops a job at its target, or where it runs its own tail at its limit less its setup, wind-down and margin', function (): void {
    $stop = new JobStop(Seconds::of(600.0), Seconds::of(45.0), new Absent());
    $jobs = JobTail::of(Seconds::of(3600.0), 19);

    expect($jobs->stop($stop, Seconds::of(60.0), Seconds::of(35.0)))->toEqual(Seconds::of(460.0))
        ->and($jobs->running()->stop($stop, Seconds::of(60.0), Seconds::of(35.0)))->toEqual(Seconds::of(3460.0))
        ->and($jobs->running()->runs())->toBeTrue()
        ->and($jobs->runs())->toBeFalse();
});

it('never runs the tail of a plan that knew no limit', function (): void {
    $unknown = JobTail::unknown();

    expect($unknown->running()->runs())->toBeFalse()
        ->and($unknown->limit())->toBeInstanceOf(NotGiven::class)
        ->and($unknown->pool())->toBeInstanceOf(NotGiven::class);
});
