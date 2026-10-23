<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

/**
 * How sure a sampled run's interval is to hold a tree's true score,
 * `sampling.confidence`: 90, 95 or 99 percent (ADR-0020).
 */
enum Confidence: int
{
    case Ninety = 90;
    case NinetyFive = 95;
    case NinetyNine = 99;

    /** The standard normal quantile 1.645 a two-sided 90 percent interval spans each side of its centre. */
    private const float NINETY = 1.6448536269514722;

    /** The standard normal quantile 1.960 a two-sided 95 percent interval spans each side of its centre. */
    private const float NINETY_FIVE = 1.959963984540054;

    /** The standard normal quantile 2.576 a two-sided 99 percent interval spans each side of its centre. */
    private const float NINETY_NINE = 2.5758293035489004;

    /** How many standard errors the interval spans each side of its centre. */
    public function quantile(): float
    {
        return match ($this) {
            self::Ninety => self::NINETY,
            self::NinetyFive => self::NINETY_FIVE,
            self::NinetyNine => self::NINETY_NINE,
        };
    }
}
