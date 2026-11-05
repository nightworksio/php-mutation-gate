<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The limit of each test of a run a watchdog holds it to (ADR-0090): its
 * own time, as a coverage map measured it, under a per-test limit. A test
 * the map did not time has none, and the run is held to its silence limit
 * while it runs.
 */
final readonly class TestWatch
{
    private function __construct(private PerTestLimit $limit, private TestTimes $times)
    {
    }

    public static function of(PerTestLimit $limit, TestTimes $times): self
    {
        return new self($limit, $times);
    }

    /** Whether it holds any test to a limit of its own. */
    public function isOn(): bool
    {
        return $this->limit->isOn() && ! $this->times->isEmpty();
    }

    public function limit(): PerTestLimit
    {
        return $this->limit;
    }

    /** The own time of each test it can hold to a limit. */
    public function times(): TestTimes
    {
        return $this->times;
    }

    /** How long a test may run; none where the map did not time it, timed it at no seconds, or the limit is off. */
    public function limitOf(TestId $test): Seconds|NotGiven
    {
        $own = $this->times->secondsOf($test);

        return $this->limit->isOn() && $own instanceof Seconds && $own->seconds() > 0.0
            ? $this->limit->allowing($own)
            : NotGiven::value();
    }
}
