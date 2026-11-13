<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_key_exists;
use function array_keys;
use function array_map;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

/** How long each test a coverage map timed took, by its id. */
final readonly class TestDurations
{
    /** @param array<array-key, Seconds> $durations each timed test's duration, by id */
    private function __construct(private array $durations)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These, with how long each of these tests took; a test timed twice took the later. */
    public function with(TimedTest ...$timed): self
    {
        $durations = $this->durations;

        foreach ($timed as $each) {
            $durations[$each->test()->value()] = $each->seconds();
        }

        return new self($durations);
    }

    /** These, with another's laid beside them: a test both timed keeps the longer time. */
    public function andLonger(self $other): self
    {
        $durations = $this->durations;

        foreach ($other->durations as $test => $duration) {
            $mine = array_key_exists($test, $durations) ? $durations[$test] : Unmeasured::duration();
            $longer = $mine instanceof Seconds && $mine->seconds() >= $duration->seconds();
            $durations[$test] = $longer ? $mine : $duration;
        }

        return new self($durations);
    }

    /** How long a test took; unmeasured where it was not timed. */
    public function of(TestId $test): Seconds|Unmeasured
    {
        return array_key_exists($test->value(), $this->durations)
            ? $this->durations[$test->value()]
            : Unmeasured::duration();
    }

    /**
     * How long the whole suite takes, test after test, as they timed it:
     * what a runner's opening run costs before it has measured one of its
     * own. A test not timed adds nothing.
     */
    public function total(): Seconds
    {
        $seconds = 0.0;

        foreach ($this->durations as $duration) {
            $seconds += $duration->seconds();
        }

        return Seconds::of($seconds);
    }

    /** The tests timed, each as the map knows it. */
    public function tests(): TestIds
    {
        return TestIds::of(...array_map(
            static fn(int|string $test): TestId => TestId::of(sprintf('%s', $test)),
            array_keys($this->durations),
        ));
    }
}
