<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

$map = CoverageMap::empty()
    ->timed(TestId::of('T::adds'), Seconds::of(0.25))
    ->timed(TestId::of('T::subtracts'), Seconds::of(0.5));

it('times tests one after another, as the map timed them', function () use ($map): void {
    expect(OwnTime::of($map, TestIds::of(TestId::of('T::adds'), TestId::of('T::subtracts'))))->toEqual(Seconds::of(0.75))
        ->and(OwnTime::of($map, TestIds::of(TestId::of('T::adds'))))->toEqual(Seconds::of(0.25));
});

it('leaves tests unmeasured where the map did not time one of them, or where there are none', function () use ($map): void {
    expect(OwnTime::of($map, TestIds::of(TestId::of('T::adds'), TestId::of('T::untimed'))))->toEqual(Unmeasured::duration())
        ->and(OwnTime::of($map, TestIds::none()))->toEqual(Unmeasured::duration());
});
