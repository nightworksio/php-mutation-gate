<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestTime;
use NightWorksIO\MutationGate\Core\Test\TestTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('keeps each test\'s own time once, the last given, and gives each back in the order first given', function (): void {
    $times = TestTimes::of(
        TestTime::of(TestId::of('MoneyTest::adds'), Seconds::of(1.0)),
        TestTime::of(TestId::of('7'), Seconds::of(0.5)),
        TestTime::of(TestId::of('MoneyTest::adds'), Seconds::of(2.0)),
    );

    expect($times->secondsOf(TestId::of('MoneyTest::adds')))->toEqual(Seconds::of(2.0))
        ->and($times->secondsOf(TestId::of('7')))->toEqual(Seconds::of(0.5))
        ->and($times->secondsOf(TestId::of('MoneyTest::subtracts')))->toEqual(NotGiven::value())
        ->and($times->isEmpty())->toBeFalse()
        ->and([...$times])->toEqual([
            TestTime::of(TestId::of('MoneyTest::adds'), Seconds::of(2.0)),
            TestTime::of(TestId::of('7'), Seconds::of(0.5)),
        ]);
});

it('holds nothing where none is given', function (): void {
    expect(TestTimes::none()->isEmpty())->toBeTrue()
        ->and(TestTimes::of()->isEmpty())->toBeTrue()
        ->and([...TestTimes::none()])->toBe([]);
});
