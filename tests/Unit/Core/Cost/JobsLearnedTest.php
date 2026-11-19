<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\JobsLearned;
use NightWorksIO\MutationGate\Core\Cost\JobTimes;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('learns the longest setup and wind-down of the jobs, and the median of their second passes\' factors', function (): void {
    $learned = JobsLearned::of(
        JobTimes::of('p', Seconds::of(40.0), Seconds::of(8.0), 0.9),
        JobTimes::of('p', Seconds::of(55.0), NotGiven::value(), 0.5),
        JobTimes::of('p', NotGiven::value(), Seconds::of(12.0), 0.7),
        JobTimes::of('p', Seconds::of(45.0), Seconds::of(3.0), NotGiven::value()),
    );
    $even = JobsLearned::of(JobTimes::of('p', NotGiven::value(), NotGiven::value(), 0.4), JobTimes::of('p', NotGiven::value(), NotGiven::value(), 0.8));

    expect($learned->setup())->toEqual(Seconds::of(55.0))
        ->and($learned->windDown())->toEqual(Seconds::of(12.0))
        ->and($learned->passTwo())->toBe(0.7)
        ->and($learned->teachesTheStop())->toBeTrue()
        ->and($learned->setupOr(Seconds::of(60.0)))->toEqual(Seconds::of(55.0))
        ->and($learned->windDownOr(Seconds::of(35.0)))->toEqual(Seconds::of(12.0))
        ->and($even->passTwo())->toEqualWithDelta(0.6, 1e-9)
        ->and($even->teachesTheStop())->toBeFalse();
});

it('learns nothing of no job, and takes what it is given in its place', function (): void {
    $none = JobsLearned::of();

    expect($none)->toEqual(JobsLearned::none())
        ->and($none->setup())->toBeInstanceOf(NotGiven::class)
        ->and($none->windDown())->toBeInstanceOf(NotGiven::class)
        ->and($none->passTwo())->toBeInstanceOf(NotGiven::class)
        ->and($none->teachesTheStop())->toBeFalse()
        ->and($none->setupOr(Seconds::of(60.0)))->toEqual(Seconds::of(60.0))
        ->and($none->windDownOr(Seconds::of(35.0)))->toEqual(Seconds::of(35.0));
});
