<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use function max;
use function min;
use function sqrt;

/**
 * Where a share most likely lies, from 0 for none to 1 for all: Wilson's
 * score interval about an observed share of so many mutants (ADR-0020),
 * or the one share an exact count gives.
 */
final readonly class Bounds
{
    private function __construct(private float $lower, private float $upper)
    {
    }

    /** The one share a count of every mutant gives, with nothing either side of it. */
    public static function exactly(float $share): self
    {
        return new self($share, $share);
    }

    /** Every share there is: what a sample with nothing counted says. */
    public static function anything(): self
    {
        return new self(0.0, 1.0);
    }

    /** Wilson's score interval about this share of so many mutants, as many standard errors each side as asked. */
    public static function wilson(float $share, float $mutants, Confidence $confidence): self
    {
        $quantile = $confidence->quantile();
        $squared = $quantile * $quantile;
        $denominator = 1 + $squared / $mutants;
        $centre = ($share + $squared / (2 * $mutants)) / $denominator;
        $spread = $quantile / (2 * $mutants);
        $half = $quantile * sqrt($share * (1 - $share) / $mutants + $spread * $spread) / $denominator;

        return new self(max(0.0, $centre - $half), min(1.0, $centre + $half));
    }

    public function lower(): float
    {
        return $this->lower;
    }

    public function upper(): float
    {
        return $this->upper;
    }
}
