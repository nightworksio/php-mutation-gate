<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;

/**
 * The test a run is running and since when, as its events tell (ADR-0090):
 * one that started and has not finished. A run between tests runs none.
 *
 * @internal
 */
final readonly class TestClock
{
    private function __construct(private TestStart|NotGiven $running)
    {
    }

    /** A run running no test. */
    public static function idle(): self
    {
        return new self(NotGiven::value());
    }

    /** The run, a test having started at these seconds of the clock. */
    public function started(TestId $test, float $at): self
    {
        return new self(TestStart::of($test, $at));
    }

    /** The run, its test having finished. */
    public function finished(): self
    {
        return self::idle();
    }

    /**
     * The test it is running and the limit it has run past, at these
     * seconds of the clock, under this watch; none where it runs no test,
     * the test has no limit, or it is within it.
     */
    public function pastLimit(TestWatch $watch, float $now): Overrun|NotGiven
    {
        return $this->running instanceof TestStart ? $this->running->pastLimit($watch, $now) : $this->running;
    }
}
