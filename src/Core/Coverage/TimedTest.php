<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A test and how long it took, as a coverage map is read: many of these time a map at once. */
final readonly class TimedTest
{
    private function __construct(private TestId $test, private Seconds $seconds)
    {
    }

    public static function of(string $test, float $seconds): self
    {
        return new self(TestId::of($test), Seconds::of($seconds));
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
