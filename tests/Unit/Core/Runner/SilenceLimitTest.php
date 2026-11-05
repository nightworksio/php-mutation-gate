<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Runner\Progress;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\TestEvents;
use NightWorksIO\MutationGate\Core\Runner\TestWatch;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestTime;
use NightWorksIO\MutationGate\Core\Test\TestTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A watch that holds one test to its own limit. */
function oneTestWatch(): TestWatch
{
    return TestWatch::of(PerTestLimit::standard(), TestTimes::of(TestTime::of(TestId::of('Money::adds'), Seconds::of(1.0))));
}

it('watches a process\'s tests under its silence limit, in the form its file tells them', function (): void {
    $silence = SilenceLimit::of(Seconds::of(9.0), 'events.txt')->watchingTests(oneTestWatch(), TestEvents::PhpUnitText);

    expect($silence->limit())->toEqual(Seconds::of(9.0))
        ->and($silence->progress())->toBe('events.txt')
        ->and($silence->tests())->toEqual(oneTestWatch())
        ->and($silence->events())->toBe(TestEvents::PhpUnitText);
});

it('watches nothing more where the watch holds no test', function (): void {
    $silence = SilenceLimit::of(Seconds::of(9.0), 'events.txt');

    expect($silence->watchingTests(TestWatch::of(PerTestLimit::off(), TestTimes::none()), TestEvents::NativeResults))->toEqual($silence)
        ->and($silence->tests())->toEqual(NotGiven::value());
});

it('stops a process at its silence limit, at a test\'s own, at both, or not before its deadline', function (): void {
    $off = TestWatch::of(PerTestLimit::off(), TestTimes::none());

    expect(SilenceLimit::stopping(Seconds::of(9.0), NotGiven::value(), 'r', TestEvents::NativeResults))->toEqual(SilenceLimit::of(Seconds::of(9.0), 'r'))
        ->and(SilenceLimit::stopping(Seconds::of(9.0), $off, 'r', TestEvents::NativeResults))->toEqual(SilenceLimit::of(Seconds::of(9.0), 'r'))
        ->and(SilenceLimit::stopping(Seconds::of(9.0), oneTestWatch(), 'r', TestEvents::NativeResults))
        ->toEqual(SilenceLimit::of(Seconds::of(9.0), 'r')->watchingTests(oneTestWatch(), TestEvents::NativeResults))
        ->and(SilenceLimit::stopping(NotGiven::value(), oneTestWatch(), 'r', TestEvents::NativeResults))
        ->toEqual(SilenceLimit::none('r')->watchingTests(oneTestWatch(), TestEvents::NativeResults))
        ->and(SilenceLimit::stopping(NotGiven::value(), $off, 'r', TestEvents::NativeResults))->toEqual(NotGiven::value())
        ->and(SilenceLimit::stopping(NotGiven::value(), NotGiven::value(), 'r', TestEvents::NativeResults))->toEqual(NotGiven::value());
});

it('starts a process\'s progress under its silence limit, and none where it has none to stall at', function (): void {
    expect(Progress::of(SilenceLimit::of(Seconds::of(9.0), 'r')))->toEqual(Progress::under(Seconds::of(9.0)))
        ->and(Progress::of(SilenceLimit::none('r')))->toEqual(NotGiven::value())
        ->and(Progress::of(NotGiven::value()))->toEqual(NotGiven::value())
        ->and(SilenceLimit::none('r')->limit())->toEqual(NotGiven::value());
});
