<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

/**
 * How many times the plan times a mutant's own run starting, taking the
 * fastest: one run on a busy machine is noisy, and the fastest of a few is
 * the least noisy estimate of a fixed cost (ADR-0006, decision 4).
 */
final readonly class StartUpSamples
{
    /** How many runs the plan times unless told otherwise. */
    private const int STANDARD = 3;

    /** @param positive-int $runs */
    private function __construct(private int $runs)
    {
    }

    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    /** @return positive-int */
    public function runs(): int
    {
        return $this->runs;
    }
}
