<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\JobStop;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('stops a job\'s gate at its target less its setup, its wind-down and the margin, no later than a job limit, and at none where nothing is left', function (float $limit, float $setup, float $windDown, float $stop): void {
    $jobStop = new JobStop(Seconds::of(600.0), Seconds::of(45.0), $limit > 0.0 ? Seconds::of($limit) : Absent::setting());

    expect($jobStop->after(Seconds::of($setup), Seconds::of($windDown)))->toEqual(Seconds::of($stop));
})->with([
    'the target alone' => [0.0, 50.0, 10.0, 495.0],
    'a longer job limit' => [3600.0, 50.0, 10.0, 495.0],
    'a shorter job limit' => [300.0, 50.0, 10.0, 300.0],
    'a setup past the target' => [0.0, 700.0, 10.0, 0.0],
]);

it('takes the standard wind-down where none was measured, and says what it is cut from', function (): void {
    $jobStop = new JobStop(Seconds::of(600.0), Seconds::of(45.0), Absent::setting());

    expect($jobStop->unmeasured(Seconds::of(60.0)))->toEqual(Seconds::of(600.0 - 60.0 - JobStop::WIND_DOWN_SECONDS - 45.0))
        ->and($jobStop->target())->toEqual(Seconds::of(600.0))
        ->and($jobStop->margin())->toEqual(Seconds::of(45.0))
        ->and($jobStop->jobLimit())->toEqual(Absent::setting());
});

it('stops at the job limit alone where the config sets it and no target', function (): void {
    expect(new JobStop(Seconds::of(600.0), Seconds::of(45.0), Seconds::of(3600.0), limitAlone: true)->after(Seconds::of(50.0), Seconds::of(10.0)))
        ->toEqual(Seconds::of(3600.0))
        ->and(new JobStop(Seconds::of(600.0), Seconds::of(45.0), Absent::setting(), limitAlone: true)->after(Seconds::of(50.0), Seconds::of(10.0)))
        ->toEqual(Seconds::of(495.0));
});

it('stops a job that runs its own tail at shards.jobLimit where it is set, and otherwise at its limit less its setup, wind-down and margin', function (): void {
    expect(new JobStop(Seconds::of(600.0), Seconds::of(45.0), Seconds::of(1200.0))->tailAfter(Seconds::of(3600.0), Seconds::of(50.0), Seconds::of(10.0)))
        ->toEqual(Seconds::of(1200.0))
        ->and(new JobStop(Seconds::of(600.0), Seconds::of(45.0), Absent::setting())->tailAfter(Seconds::of(3600.0), Seconds::of(50.0), Seconds::of(10.0)))
        ->toEqual(Seconds::of(3495.0))
        ->and(new JobStop(Seconds::of(600.0), Seconds::of(45.0), Absent::setting())->tailAfter(Seconds::of(60.0), Seconds::of(50.0), Seconds::of(10.0)))
        ->toEqual(Seconds::of(0.0));
});
