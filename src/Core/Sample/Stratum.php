<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use function min;

/**
 * A tree's sampled stratum (ADR-0020, decisions 23 and 24): the tallies of
 * the units its sample ran, how many units it holds, and how many mutants
 * it is estimated to hold. Its share killed is a ratio estimate, killed
 * over counted summed over the sampled units, and its interval Wilson's on
 * the effective sample size: the sampled mutants over the design effect,
 * the ratio's linearised variance over the binomial variance of as many
 * independent mutants, taken as 1 where the units vary no more than those
 * would, and corrected for the share of its units sampled. Every unit
 * sampled leaves nothing to estimate.
 */
final readonly class Stratum
{
    private function __construct(private Spread $sampled, private int $units, private int $mutants)
    {
    }

    /** A stratum of so many units, estimated to hold so many mutants, of which these units' tallies were sampled. */
    public static function of(int $units, int $mutants, Spread $sampled): self
    {
        return new self($sampled, $units, $mutants);
    }

    /** No stratum at all: a tree whose every unit is known exactly. */
    public static function none(): self
    {
        return new self(Spread::of(), 0, 0);
    }

    /** How many units it holds, sampled or not. */
    public function units(): int
    {
        return $this->units;
    }

    /** How many of its units the sample ran. */
    public function sampledUnits(): int
    {
        return $this->sampled->units();
    }

    /** How many mutants it is estimated to hold. */
    public function mutants(): int
    {
        return $this->mutants;
    }

    /** The share of its mutants estimated killed. */
    public function share(): float
    {
        return $this->sampled->ratio();
    }

    /** Where that share lies, at this confidence. */
    public function bounds(Confidence $confidence): Bounds
    {
        $counted = $this->sampled->sum()->counted();

        return match (true) {
            $this->sampled->units() >= $this->units => Bounds::exactly($this->share()),
            $counted === 0 => Bounds::anything(),
            default => Bounds::wilson($this->share(), $this->effective(), $confidence),
        };
    }

    /** The sampled mutants over the design effect, corrected for the share of the units sampled. */
    private function effective(): float
    {
        $counted = $this->sampled->sum()->counted();
        $share = $this->share();
        $mean = $this->sampled->meanCounted();
        $variance = $this->sampled->variance() / ($this->sampled->units() * $mean * $mean);
        $binomial = $share * (1 - $share);
        $independent = $variance > 0 && $binomial > 0 ? min($counted, $binomial / $variance) : $counted;

        return $independent / (1 - $this->sampled->units() / $this->units);
    }
}
