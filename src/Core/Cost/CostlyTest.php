<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * A test among a run's costliest (ADR-0230): its own seconds, as the
 * coverage map timed it, and how many of the mutants the run judged cover a
 * line it runs. Each of those mutants may run it once, so their product is
 * the most it can have cost the run.
 */
final readonly class CostlyTest
{
    private function __construct(private TestId $test, private Seconds $own, private int $mutants)
    {
    }

    public static function of(TestId $test, Seconds $own, int $mutants): self
    {
        return new self($test, $own, $mutants);
    }

    public function test(): TestId
    {
        return $this->test;
    }

    public function own(): Seconds
    {
        return $this->own;
    }

    /** How many of the mutants the run judged sit on a line it runs. */
    public function mutants(): int
    {
        return $this->mutants;
    }

    /** The most it can have cost the run: its own seconds once for each of those mutants. */
    public function atMost(): Seconds
    {
        return Seconds::of($this->own->seconds() * $this->mutants);
    }
}
