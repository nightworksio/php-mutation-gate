<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * How much room a memory cap must leave above what the unmutated suite held
 * for the cap's stop to count as a kill (ADR-0004, decision 9). A cap that
 * holds at least twice the suite's peak leaves it: a mutant stopped by that
 * cap needed far more than its suite, so it ran away. Under it, a mutant
 * the cap stopped may have needed only a little more than the suite did,
 * which says nothing of its tests.
 */
final readonly class Headroom
{
    /** How many times the suite's peak the cap holds, as standard. */
    private const int TIMES = 2;

    private function __construct(private int $times)
    {
    }

    public static function standard(): self
    {
        return new self(self::TIMES);
    }

    /** Whether the cap leaves the room above the suite's peak: no cap always does. */
    public function isLeftBy(MemoryCap $cap, MemoryCap $peak): bool
    {
        return ! $cap->caps() || $cap->bytes() >= $this->neededFor($peak)->bytes();
    }

    /** The smallest cap that leaves the room above the suite's peak. */
    public function neededFor(MemoryCap $peak): MemoryCap
    {
        return MemoryCap::atLeast($peak->bytes() * $this->times);
    }
}
