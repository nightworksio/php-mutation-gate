<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Runner\TestWatch;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestTime;
use NightWorksIO\MutationGate\Core\Test\TestTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('holds each timed test to its own limit, and an untimed one or one timed at no seconds to none', function (): void {
    $times = TestTimes::of(
        TestTime::of(TestId::of('MoneyTest::adds'), Seconds::of(2.0)),
        TestTime::of(TestId::of('MoneyTest::idle'), Seconds::of(0.0)),
    );
    $watch = TestWatch::of(PerTestLimit::standard(), $times);

    expect($watch->limitOf(TestId::of('MoneyTest::adds')))->toEqual(Seconds::of(6.0))
        ->and($watch->limitOf(TestId::of('MoneyTest::idle')))->toEqual(NotGiven::value())
        ->and($watch->limitOf(TestId::of('MoneyTest::subtracts')))->toEqual(NotGiven::value())
        ->and($watch->isOn())->toBeTrue()
        ->and($watch->limit())->toEqual(PerTestLimit::standard())
        ->and($watch->times())->toBe($times);
});

it('holds no test where the limit is off, and is off where it times no test', function (): void {
    $times = TestTimes::of(TestTime::of(TestId::of('MoneyTest::adds'), Seconds::of(2.0)));

    expect(TestWatch::of(PerTestLimit::off(), $times)->limitOf(TestId::of('MoneyTest::adds')))->toEqual(NotGiven::value())
        ->and(TestWatch::of(PerTestLimit::off(), $times)->isOn())->toBeFalse()
        ->and(TestWatch::of(PerTestLimit::standard(), TestTimes::none())->isOn())->toBeFalse();
});
