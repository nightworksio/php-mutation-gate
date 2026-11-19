<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\JobTimes;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('teaches its second pass\'s factor only as a job of the second wave, and nothing without a platform or a measure', function (): void {
    $job = JobTimes::of('p', Seconds::of(50.0), Seconds::of(9.0), 0.7);

    expect($job->pace(share: true))->toEqual($job)
        ->and($job->pace(share: false))->toEqual(JobTimes::of('p', Seconds::of(50.0), Seconds::of(9.0), NotGiven::value()))
        ->and($job->teaches())->toBeTrue()
        ->and(JobTimes::of('', Seconds::of(50.0), NotGiven::value(), NotGiven::value())->teaches())->toBeFalse()
        ->and(JobTimes::of('p', NotGiven::value(), NotGiven::value(), NotGiven::value())->teaches())->toBeFalse()
        ->and(JobTimes::of('p', NotGiven::value(), NotGiven::value(), 0.0)->teaches())->toBeTrue()
        ->and(JobTimes::none()->teaches())->toBeFalse();
});
