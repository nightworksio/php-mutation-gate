<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * A test a run started, and at what seconds of the clock (ADR-0090).
 *
 * @internal
 */
final readonly class TestStart
{
    private function __construct(private TestId $test, private float $at)
    {
    }

    public static function of(TestId $test, float $at): self
    {
        return new self($test, $at);
    }

    /**
     * The test and the limit it has run past at these seconds of the clock,
     * under this watch; none where the test has no limit, or is within it.
     */
    public function pastLimit(TestWatch $watch, float $now): Overrun|NotGiven
    {
        $limit = $watch->limitOf($this->test);

        return $limit instanceof Seconds && $now - $this->at > $limit->seconds()
            ? Overrun::of($this->test, $limit)
            : NotGiven::value();
    }
}
