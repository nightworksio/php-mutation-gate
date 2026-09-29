<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

use function intdiv;

/**
 * The share of counted mutants that were killed, in hundredths of a percent,
 * truncated: 2 of 3 is 66.66, never 66.67.
 */
final readonly class Score
{
    private const int HUNDREDTHS_PER_PERCENT = 100;

    private const int WHOLE = 10_000;

    private function __construct(private int $hundredths) {}

    /** @throws NotAPercentage */
    public static function ofHundredths(int $hundredths): self
    {
        if ($hundredths < 0 || $hundredths > self::WHOLE) {
            throw NotAPercentage::of($hundredths / self::HUNDREDTHS_PER_PERCENT);
        }

        return new self($hundredths);
    }

    /**
     * Killed out of counted. With nothing counted there is no score, and
     * that is never the same as every mutant killed.
     *
     * @throws NotAPercentage
     */
    public static function of(int $killed, int $counted): self|NothingToMutate
    {
        if ($counted === 0) {
            return NothingToMutate::found();
        }

        return self::ofHundredths(intdiv($killed * self::WHOLE, $counted));
    }

    public function hundredths(): int
    {
        return $this->hundredths;
    }

    public function percent(): float
    {
        return $this->hundredths / self::HUNDREDTHS_PER_PERCENT;
    }
}
