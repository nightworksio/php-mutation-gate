<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * A test that ran past the limit of its own a watchdog held it to, so its
 * run was stopped (ADR-0090): the test, by its id, and that limit.
 */
final readonly class Overrun
{
    private function __construct(private TestId $test, private Seconds $limit)
    {
    }

    public static function of(TestId $test, Seconds $limit): self
    {
        return new self($test, $limit);
    }

    /** The test that ran past its limit. */
    public function test(): TestId
    {
        return $this->test;
    }

    /** The limit of its own it ran past. */
    public function limit(): Seconds
    {
        return $this->limit;
    }
}
