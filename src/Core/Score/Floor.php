<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

use function floor;
use function intdiv;
use function round;

/**
 * The lowest score a set of mutants may have, in hundredths of a percent.
 * A floor records no more than was written: 83.419 is 83.41.
 */
final readonly class Floor
{
    private const int HUNDREDTHS_PER_PERCENT = 100;

    private const int WHOLE = 10_000;

    /** Enough decimals to undo binary noise, such as 0.29 × 100 being 28.999…, and none that matter. */
    private const int NOISE = 6;

    private function __construct(private int $hundredths)
    {
    }

    /** @throws NotAPercentage */
    public static function of(float $percent): self
    {
        return self::ofHundredths((int) floor(round($percent * self::HUNDREDTHS_PER_PERCENT, self::NOISE)));
    }

    /** @throws NotAPercentage */
    public static function ofHundredths(int $hundredths): self
    {
        if ($hundredths < 0 || $hundredths > self::WHOLE) {
            throw NotAPercentage::of($hundredths / self::HUNDREDTHS_PER_PERCENT);
        }

        return new self($hundredths);
    }

    /** Every mutant killed: the highest floor there is. */
    public static function whole(): self
    {
        return new self(self::WHOLE);
    }

    public function hundredths(): int
    {
        return $this->hundredths;
    }

    public function percent(): float
    {
        return $this->hundredths / self::HUNDREDTHS_PER_PERCENT;
    }

    /** The percent as a config writes it: a whole percent as a whole number, `83.41` as it is. */
    public function written(): int|float
    {
        return $this->hundredths % self::HUNDREDTHS_PER_PERCENT === 0
            ? intdiv($this->hundredths, self::HUNDREDTHS_PER_PERCENT)
            : $this->percent();
    }
}
