<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A test and its own time, as a coverage map measured it. */
final readonly class TestTime
{
    private function __construct(private TestId $test, private Seconds $seconds)
    {
    }

    public static function of(TestId $test, Seconds $seconds): self
    {
        return new self($test, $seconds);
    }

    public function test(): TestId
    {
        return $this->test;
    }

    public function seconds(): Seconds
    {
        return $this->seconds;
    }
}
