<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Overrun;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\TestEvents;
use NightWorksIO\MutationGate\Core\Runner\TestLog;
use NightWorksIO\MutationGate\Core\Runner\TestWatch;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestTime;
use NightWorksIO\MutationGate\Core\Test\TestTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A watch that holds `Money::adds (1)` and `Money::subtracts` to three seconds each. */
function moneyWatch(): TestWatch
{
    return TestWatch::of(PerTestLimit::standard(), TestTimes::of(
        TestTime::of(TestId::of('Money::adds (1)'), Seconds::of(0.5)),
        TestTime::of(TestId::of('Money::subtracts'), Seconds::of(0.5)),
    ));
}

it('names the test running past its own limit, in each form a run tells its tests in', function (TestEvents $events, string $started, string $finished): void {
    $log = TestLog::of($events, moneyWatch())->after($started, 10.0);

    expect($log->pastLimit(13.0))->toEqual(NotGiven::value())
        ->and($log->pastLimit(13.5))->toEqual(Overrun::of(TestId::of('Money::adds (1)'), Seconds::of(3.0)))
        ->and($log->after($finished, 12.0)->pastLimit(20.0))->toEqual(NotGiven::value());
})->with([
    'PHPUnit\'s events in text' => [
        TestEvents::PhpUnitText,
        "Test Runner Started\nTest Preparation Started (Money::adds (1))\n",
        "Test Passed (Money::adds (1))\nTest Finished (Money::adds (1))\n",
    ],
    'the extension\'s lines' => [TestEvents::NativeResults, "started Money%3A%3Aadds%20%281%29\n", "passed\n"],
    'a mutant process\'s beats' => [
        TestEvents::Heartbeats,
        fn(): string => sprintf("%s\nPHP Warning: deprecated%sMoney%%3A%%3Aadds%%20%%281%%29\n", TestEvents::BEAT, TestEvents::TEST_STARTED),
        fn(): string => sprintf("%s\n", TestEvents::BEAT),
    ],
]);

it('reads a line only once it is written whole, and times a test from the read that told of its start', function (): void {
    $log = TestLog::of(TestEvents::NativeResults, moneyWatch())->after('started Money%3A%3Asub', 1.0)->after("tracts\n", 5.0);

    expect($log->pastLimit(7.9))->toEqual(NotGiven::value())
        ->and($log->pastLimit(8.1))->toEqual(Overrun::of(TestId::of('Money::subtracts'), Seconds::of(3.0)));
});

it('holds a test no map timed to no limit of its own, and a run between tests to none', function (): void {
    $untimed = TestLog::of(TestEvents::PhpUnitText, moneyWatch())->after("Test Preparation Started (Money::divides)\n", 0.0);
    $idle = TestLog::of(TestEvents::PhpUnitText, moneyWatch())->after("Test Runner Started\n", 0.0);

    expect($untimed->pastLimit(100.0))->toEqual(NotGiven::value())
        ->and($idle->pastLimit(100.0))->toEqual(NotGiven::value());
});

it('reads an id whose closing bracket is missing to the end of its line', function (): void {
    $log = TestLog::of(TestEvents::PhpUnitText, moneyWatch())->after("Test Preparation Started (Money::subtracts\n", 0.0);

    expect($log->pastLimit(4.0))->toEqual(Overrun::of(TestId::of('Money::subtracts'), Seconds::of(3.0)));
});

it('keeps the test running across lines that neither start nor end one', function (): void {
    $log = TestLog::of(TestEvents::Heartbeats, moneyWatch())
        ->after(sprintf("%sMoney%%3A%%3Asubtracts\n", TestEvents::TEST_STARTED), 0.0)
        ->after("PHP Notice: something\n", 2.0);

    expect($log->pastLimit(3.5))->toEqual(Overrun::of(TestId::of('Money::subtracts'), Seconds::of(3.0)));
});

it('reads the log of a process whose silence limit watches its tests, and none of one that watches none', function (): void {
    $watched = SilenceLimit::of(Seconds::of(9.0), 'results.jsonl')->watchingTests(moneyWatch(), TestEvents::NativeResults);
    $log = TestLog::under($watched);

    expect($log instanceof TestLog ? $log->after("started Money%3A%3Asubtracts\n", 0.0)->pastLimit(3.5) : $log)
        ->toEqual(Overrun::of(TestId::of('Money::subtracts'), Seconds::of(3.0)))
        ->and(TestLog::under(SilenceLimit::of(Seconds::of(9.0), 'results.jsonl')))->toEqual(NotGiven::value());
});

it('keeps a test of the extension\'s lines running across a blank line, which ends none', function (): void {
    $log = TestLog::of(TestEvents::NativeResults, moneyWatch())->after("started Money%3A%3Asubtracts\n\n", 0.0);

    expect($log->pastLimit(3.5))->toEqual(Overrun::of(TestId::of('Money::subtracts'), Seconds::of(3.0)));
});
