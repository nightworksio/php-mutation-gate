<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\JobTimes;
use NightWorksIO\MutationGate\Core\Cost\SharesTimed;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('counts the shares it times, those added after the ones before', function (): void {
    $first = JobTimes::of('a', Seconds::of(10.0), NotGiven::value(), NotGiven::value());
    $second = JobTimes::of('b', NotGiven::value(), Seconds::of(2.0), 0.5);
    $shares = SharesTimed::none()->and($first)->and($second);

    expect(count(SharesTimed::none()))->toBe(0)
        ->and(count($shares))->toBe(2)
        ->and([...$shares])->toBe([$first, $second]);
});
