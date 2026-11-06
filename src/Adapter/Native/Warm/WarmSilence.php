<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native\Warm;

use NightWorksIO\MutationGate\Core\Config\PerTestOptions;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\TestEvents;
use NightWorksIO\MutationGate\Core\Runner\TestWatch;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestTime;
use NightWorksIO\MutationGate\Core\Test\TestTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * How a job writes a run's silence limit for the warm worker that makes it:
 * the limit, where it has one, the file whose growth is the child's progress,
 * and each test's own limit with the form the file tells its tests in, where
 * its tests are watched (ADR-0090).
 *
 * @internal
 */
final readonly class WarmSilence
{
    private const string SILENCE = 'silence';

    private const string PROGRESS = 'progress';

    private const string TESTS = 'watched';

    private const string MULTIPLE = PerTestOptions::MULTIPLE;

    private const string FLOOR = 'least';

    private const string TIMES = 'times';

    private const string EVENTS = 'events';

    /**
     * What a job writes of this silence limit; nothing where the run has none.
     *
     * @return list<Member>
     */
    public static function members(SilenceLimit|NotGiven $silence): array
    {
        if (! $silence instanceof SilenceLimit) {
            return [];
        }

        $limit = $silence->limit();
        $tests = $silence->tests();

        return [
            ...$limit instanceof Seconds ? [Member::of(self::SILENCE, $limit->seconds())] : [],
            Member::of(self::PROGRESS, $silence->progress()),
            ...$tests instanceof TestWatch ? [Member::of(self::TESTS, self::watch($tests, $silence->events()))] : [],
        ];
    }

    /** The silence limit a job wrote for a run; none where it wrote none. */
    public static function read(Node $at): SilenceLimit|NotGiven
    {
        $progress = $at->field(self::PROGRESS);

        if (! $progress->isPresent()) {
            return NotGiven::value();
        }

        $limit = $at->field(self::SILENCE);
        $silence = $limit->isPresent()
            ? SilenceLimit::of(Seconds::of($limit->number()), $progress->text())
            : SilenceLimit::none($progress->text());
        $tests = $at->field(self::TESTS);

        return $tests->isPresent() ? self::watched($silence, $tests) : $silence;
    }

    private static function watch(TestWatch $watch, TestEvents $events): Json
    {
        $times = [];

        foreach ($watch->times() as $time) {
            $times[] = Member::of($time->test()->value(), $time->seconds()->seconds());
        }

        return Json::object(
            Member::of(self::MULTIPLE, $watch->limit()->multiple()),
            Member::of(self::FLOOR, $watch->limit()->floor()->seconds()),
            Member::of(self::TIMES, Json::object(...$times)),
            Member::of(self::EVENTS, $events->name),
        );
    }

    /** This silence limit, its tests watched as a job wrote. */
    private static function watched(SilenceLimit $silence, Node $tests): SilenceLimit
    {
        $times = [];

        foreach ($tests->field(self::TIMES)->entries() as $test => $seconds) {
            $times[] = TestTime::of(TestId::of(sprintf('%s', $test)), Seconds::of($seconds->number()));
        }

        $limit = PerTestLimit::of(
            $tests->field(self::MULTIPLE)->integer(),
            Seconds::of($tests->field(self::FLOOR)->number()),
        );

        return $silence->watchingTests(
            TestWatch::of($limit, TestTimes::of(...$times)),
            self::eventsNamed($tests->field(self::EVENTS)->text()),
        );
    }

    /** The form of test events by its name; the gate's own extension's lines, which a native run writes, otherwise. */
    private static function eventsNamed(string $name): TestEvents
    {
        foreach (TestEvents::cases() as $events) {
            if ($events->name === $name) {
                return $events;
            }
        }

        return TestEvents::NativeResults;
    }
}
