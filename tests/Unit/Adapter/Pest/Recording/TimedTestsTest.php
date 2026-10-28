<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\TimedTests;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitEvents;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PHPUnit\Event\Facade;
use PHPUnit\Event\InvalidArgumentException;
use PHPUnit\Event\Telemetry\HRTime;

beforeEach(function (): void {
    GateEnvironment::clear();
});

afterEach(function (): void {
    GateEnvironment::restore();
    Scratch::sweep();
});

/**
 * Each test a file times, by its id, with its seconds.
 *
 * @return list<array{string, float}>
 */
function timedIn(string $file): array
{
    return array_map(
        static fn(TimedTest $timed): array => [$timed->test()->value(), $timed->seconds()->seconds()],
        TimedTests::in($file),
    );
}

/** A moment of PHPUnit's clock, these seconds and nanoseconds since it started. */
function timedAt(int $seconds, int $nanoseconds): HRTime
{
    try {
        return HRTime::fromSecondsAndNanoseconds($seconds, $nanoseconds);
    } catch (InvalidArgumentException $invalid) {
        throw new LogicException('PHPUnit refuses the moment', $invalid->getCode(), previous: $invalid);
    }
}

it('times nothing unless the adapter names a file', function (): void {
    expect(TimedTests::listening(file: false, events: new Facade()))->toBe(Off::TimingTests)
        ->and(TimedTests::listening('', new Facade()))->toBe(Off::TimingTests)
        ->and(TimedTests::fromEnvironment())->toBe(Off::TimingTests);
});

it('writes each test that finished, with the seconds since it was prepared, and none never prepared or written already', function (): void {
    $file = sprintf('%s/timed-tests.jsonl', Scratch::directory());
    $timed = TimedTests::listening($file, new Facade());

    if ($timed instanceof TimedTests) {
        $timed->prepared('T::a', timedAt(10, 0));
        $timed->finished('T::unprepared', timedAt(11, 0));
        $timed->finished('T::a', timedAt(11, 500_000_000));
        $timed->finished('T::a', timedAt(12, 0));
        $timed->prepared('T::b', timedAt(12, 0));
        $timed->finished('T::b', timedAt(12, 250_000_000));
    }

    expect($timed)->toBeInstanceOf(TimedTests::class)
        ->and(timedIn($file))->toBe([['T::a', 1.5], ['T::b', 0.25]]);
});

it('reads every line that names a test and its seconds, and none where the file is not there', function (): void {
    $file = sprintf('%s/timed-tests.jsonl', Scratch::directory());
    file_put_contents($file, implode("\n", [
        '["T::a",2]',
        '["T::b",0.25]',
        'not json',
        '["T::c"]',
        '[1,2]',
        '["T::d","slow"]',
        '{"1":3}',
        '',
    ]));

    expect(timedIn($file))->toBe([['T::a', 2.0], ['T::b', 0.25]])
        ->and(timedIn(sprintf('%s/gone.jsonl', dirname($file))))->toBe([]);
});

it('adds to a map every test the file times that the map does not, leaving the map\'s own times', function (): void {
    $file = sprintf('%s/timed-tests.jsonl', Scratch::directory());
    file_put_contents($file, "[\"T::a\",9]\n[\"T::b\",0.25]\n");
    $map = CoverageMap::empty()->timed(TestId::of('T::a'), Seconds::of(1.0));

    expect(TimedTests::onto($map, $file))
        ->toEqual(CoverageMap::empty()->timed(TestId::of('T::a'), Seconds::of(1.0))->timed(TestId::of('T::b'), Seconds::of(0.25)));
});

it('times the test running it from PHPUnit\'s events, as it is prepared and as it finishes', function (): void {
    $file = sprintf('%s/timed-tests.jsonl', Scratch::directory());
    $events = new Facade();
    TimedTests::listening($file, $events);
    PhpUnitEvents::prepared($events);
    PhpUnitEvents::finished($events);
    $timed = TimedTests::in($file);

    expect(array_map(static fn(TimedTest $each): string => $each->test()->value(), $timed))->toBe([PhpUnitEvents::runningId()])
        ->and($timed[0]->seconds()->seconds())->toBeGreaterThanOrEqual(0.0);
});
