<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

use function floor;
use function intdiv;
use function round;
use function rtrim;
use function sprintf;

/**
 * A share from 0 to 100 percent, kept in hundredths of a percent and
 * truncated rather than rounded: 83.419 is 83.41, and 2 of 3 is 66.66.
 */
final readonly class Percentage
{
    private const int HUNDREDTHS_PER_PERCENT = 100;

    private const int WHOLE = 10_000;

    /** Enough decimals to undo binary noise, such as 0.29 × 100 being 28.999…, and none that matter. */
    private const int NOISE = 6;

    private function __construct(private int $hundredths)
    {
    }

    /** All of it: 100 percent. */
    public static function whole(): self
    {
        return new self(self::WHOLE);
    }

    /** A percentage as written, or why it is none. */
    public static function parse(int|float $percent): self|NotAPercentage
    {
        return self::inHundredths(self::hundredthsOf($percent));
    }

    /** What a score or a floor is, as a percentage. */
    public static function of(Score|Floor $value): self
    {
        return new self($value->hundredths());
    }

    /** A percentage in hundredths, or why it is none. */
    public static function inHundredths(int $hundredths): self|NotAPercentage
    {
        return $hundredths >= 0 && $hundredths <= self::WHOLE
            ? new self($hundredths)
            : NotAPercentage::of($hundredths / self::HUNDREDTHS_PER_PERCENT);
    }

    /** A part of a whole that is not zero, or why it is none: a part larger than the whole, or below zero. */
    public static function share(int $part, int $whole): self|NotAPercentage
    {
        return self::inHundredths(intdiv($part * self::WHOLE, $whole));
    }

    /** A percent in hundredths, truncated rather than rounded, whether or not it is between 0 and 100. */
    public static function hundredthsOf(int|float $percent): int
    {
        return (int) floor(round($percent * self::HUNDREDTHS_PER_PERCENT, self::NOISE));
    }

    /** A number of hundredths as points with two decimals, `83.41` or `0.05`, where it is not below zero. */
    public static function points(int $hundredths): string
    {
        $whole = intdiv($hundredths, self::HUNDREDTHS_PER_PERCENT);

        return sprintf('%d.%02d', $whole, $hundredths % self::HUNDREDTHS_PER_PERCENT);
    }

    /** How much of a whole a percent is, from 0 for none to 1 for all of it. */
    public static function fractionOf(float $percent): float
    {
        return $percent * self::HUNDREDTHS_PER_PERCENT / self::WHOLE;
    }

    public function hundredths(): int
    {
        return $this->hundredths;
    }

    /** The whole percents, truncated: 94.99 is 94. */
    public function wholePercent(): int
    {
        return intdiv($this->hundredths, self::HUNDREDTHS_PER_PERCENT);
    }

    public function percent(): float
    {
        return $this->hundredths / self::HUNDREDTHS_PER_PERCENT;
    }

    /** The percentage as a file writes it: a whole number where it is one, and otherwise no trailing zero. */
    public function written(): string
    {
        return $this->hundredths % self::HUNDREDTHS_PER_PERCENT === 0
            ? sprintf('%d', intdiv($this->hundredths, self::HUNDREDTHS_PER_PERCENT))
            : rtrim(self::points($this->hundredths), '0');
    }
}
