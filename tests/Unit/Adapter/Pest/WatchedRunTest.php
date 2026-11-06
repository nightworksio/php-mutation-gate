<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\WatchedRun;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Overrun;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Runner\TestEvents;
use NightWorksIO\MutationGate\Core\Runner\TestWatch;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestTime;
use NightWorksIO\MutationGate\Core\Test\TestTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A watch that allows `T::a` three seconds. */
function watchOfA(): TestWatch
{
    return TestWatch::of(PerTestLimit::standard(), TestTimes::of(TestTime::of(TestId::of('T::a'), Seconds::of(0.5))));
}

it('stops at its silence limit once no test finished for it, before a test\'s own limit, and at the test\'s own after it', function (): void {
    $beats = sprintf("%s\n%sT%%3A%%3Aa\n", TestEvents::BEAT, TestEvents::TEST_STARTED);
    $silent = WatchedRun::of(Seconds::of(2.0), '/tmp/m', watchOfA());
    $overran = WatchedRun::of(Seconds::of(5.0), '/tmp/m', watchOfA());

    expect($silent->pastLimit($beats, 10.0))->toEqual(NotGiven::value())
        ->and($silent->pastLimit('', 12.5))->toEqual(Seconds::of(2.0))
        ->and($overran->pastLimit($beats, 10.0))->toEqual(NotGiven::value())
        ->and($overran->pastLimit('', 13.5))->toEqual(Overrun::of(TestId::of('T::a'), Seconds::of(3.0)))
        ->and($silent->mutated())->toBe('/tmp/m');
});

it('counts its silence from its last beat, and from none before its first', function (): void {
    $run = WatchedRun::of(Seconds::of(2.0), '/tmp/m', TestWatch::of(PerTestLimit::off(), TestTimes::none()));

    expect($run->pastLimit('.', 10.0))->toEqual(NotGiven::value())
        ->and($run->pastLimit('', 100.0))->toEqual(NotGiven::value())
        ->and($run->pastLimit(TestEvents::BEAT, 100.0))->toEqual(NotGiven::value())
        ->and($run->pastLimit(TestEvents::BEAT, 101.5))->toEqual(NotGiven::value())
        ->and($run->pastLimit('', 103.6))->toEqual(Seconds::of(2.0));
});

it('is watched where a silence limit or a test\'s own could stop it, and not where neither could', function (): void {
    $off = TestWatch::of(PerTestLimit::off(), TestTimes::none());

    expect(WatchedRun::of(Seconds::of(2.0), '/tmp/m', $off)->isWatched())->toBeTrue()
        ->and(WatchedRun::of(NotGiven::value(), '/tmp/m', watchOfA())->isWatched())->toBeTrue()
        ->and(WatchedRun::of(NotGiven::value(), '/tmp/m', $off)->isWatched())->toBeFalse()
        ->and(WatchedRun::of(NotGiven::value(), '/tmp/m', $off)->pastLimit(TestEvents::BEAT, 1000.0))->toEqual(NotGiven::value());
});

it('is silent only once more than its silence limit has passed since its last beat', function (): void {
    $run = WatchedRun::of(Seconds::of(2.0), '/tmp/m', TestWatch::of(PerTestLimit::off(), TestTimes::none()));

    expect($run->pastLimit(TestEvents::BEAT, 100.0))->toEqual(NotGiven::value())
        ->and($run->pastLimit('', 102.0))->toEqual(NotGiven::value())
        ->and($run->pastLimit('', 102.25))->toEqual(Seconds::of(2.0));
});
