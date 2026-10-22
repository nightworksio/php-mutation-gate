<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use function ceil;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Score\Percentage;

/**
 * How many units of a tree's sampled stratum a sample runs for its interval
 * to be about `sampling.margin` points either side (ADR-0020):
 * sized from the between-unit variance of the stratum's last results, and
 * at least as many as as many independent mutants would need, corrected
 * for the stratum's size, at least `sampling.minUnits`, and at most every
 * unit. Fewer than two last results give no variance, so every unit runs;
 * a stratum estimated to hold no mutant runs only the least.
 */
final readonly class SampleSize
{
    private function __construct(private float $margin, private Confidence $confidence, private int $least)
    {
    }

    /** Sized for this half-width in points, at this confidence, and at least this many units. */
    public static function of(float $margin, Confidence $confidence, int $least): self
    {
        return new self($margin, $confidence, $least);
    }

    /**
     * The units to run of a stratum of so many, holding this share of its
     * tree's mutants, from the last results of those of them that have one.
     */
    public function units(int $units, float $share, Spread $last): int
    {
        if ($last->units() < 2 || $last->sum()->counted() === 0) {
            return $units;
        }

        if ($share <= 0) {
            return min($units, $this->least);
        }

        $needed = $this->unbounded($share, $last);
        $corrected = (int) ceil($needed / (1 + $needed / $units));

        return min($units, max($this->least, $corrected));
    }

    /** The units an infinite stratum would need: its variance, or as many independent mutants, whichever needs more. */
    private function unbounded(float $share, Spread $last): float
    {
        $quantile = $this->confidence->quantile();
        $width = Percentage::fractionOf($this->margin) / $share;
        $mean = $last->meanCounted();
        $ratio = $last->ratio();
        $byVariance = $quantile * $quantile * $last->variance() / ($mean * $mean * $width * $width);
        $byMutants = $quantile * $quantile * $ratio * (1 - $ratio) / ($mean * $width * $width);

        return max($byVariance, $byMutants);
    }
}
