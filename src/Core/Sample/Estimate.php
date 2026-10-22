<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;

/**
 * A tree's score as a sampled run estimates it (ADR-0020, decisions 23 to
 * 25): its exact stratum's mutants, counted, and its sampled stratum's,
 * estimated, weighted by their mutants, with the sampled stratum's interval
 * scaled by its share of the tree's mutants. The lower bound is truncated to
 * hundredths and the upper rounded up, so neither flatters. A tree with no
 * sampled stratum is its exact score, with no interval either side.
 */
final readonly class Estimate
{
    private function __construct(
        private Tally $exact,
        private int $exactUnits,
        private Stratum $stratum,
        private Sample $sample,
    ) {
    }

    /**
     * A tree's estimate from the tally of its exact units, how many there
     * are, its sampled stratum, and the sample the run drew.
     */
    public static function of(Tally $exact, int $exactUnits, Stratum $stratum, Sample $sample): self
    {
        return new self($exact, $exactUnits, $stratum, $sample);
    }

    /** The estimated score, truncated to hundredths; none where the tree holds no mutant to count. */
    public function score(): Score|NothingToMutate
    {
        return $this->stratum->units() === 0
            ? Score::of($this->exact->killed(), $this->exact->counted())
            : $this->at(Percentage::hundredthsOf($this->percentOf($this->stratum->share())));
    }

    /** The lowest score the interval allows, in hundredths, truncated. */
    public function lower(): int
    {
        $score = $this->score();

        return $this->stratum->units() === 0 || $score instanceof NothingToMutate
            ? $this->hundredthsOf($score)
            : Percentage::hundredthsOf($this->percentOf($this->stratum->bounds($this->sample->confidence())->lower()));
    }

    /** The highest score the interval allows, in hundredths, rounded up. */
    public function upper(): int
    {
        $score = $this->score();

        return $this->stratum->units() === 0 || $score instanceof NothingToMutate
            ? $this->hundredthsOf($score)
            : Percentage::hundredthsUp($this->percentOf($this->stratum->bounds($this->sample->confidence())->upper()));
    }

    /** The sample the run drew: its seed, and the confidence and margin it was sized for. */
    public function sample(): Sample
    {
        return $this->sample;
    }

    /** Whether any of the tree was sampled; a tree no sample touched is its exact score. */
    public function isSampled(): bool
    {
        return $this->stratum->units() > 0;
    }

    /** How many of the tree's units the sample ran. */
    public function sampledUnits(): int
    {
        return $this->stratum->sampledUnits();
    }

    /** How many of the tree's units are known exactly. */
    public function exactUnits(): int
    {
        return $this->exactUnits;
    }

    /** How many units the tree holds: its exact ones, and its sampled stratum's, sampled or not. */
    public function totalUnits(): int
    {
        return $this->exactUnits + $this->stratum->units();
    }

    /**
     * The estimate held to a floor: passed where its lower bound reaches the
     * floor, failed where its upper bound is below it, and undecided where
     * the floor lies within the interval.
     */
    public function judgement(Floor|Exempt|Undeclared $floor): Judgement
    {
        return match (true) {
            $floor instanceof Exempt => Judgement::Exempt,
            $this->score() instanceof NothingToMutate => Judgement::NothingToMutate,
            ! $floor instanceof Floor, $this->lower() >= $floor->hundredths() => Judgement::Passed,
            $this->upper() < $floor->hundredths() => Judgement::Failed,
            default => Judgement::Undecided,
        };
    }

    /** The tree's score, in percent, where its sampled stratum kills this share of its mutants. */
    private function percentOf(float $share): float
    {
        $mutants = $this->exact->counted() + $this->stratum->mutants();
        $killed = $this->exact->killed() + $share * $this->stratum->mutants();

        return $mutants === 0 ? 0.0 : $killed / $mutants * Percentage::MOST;
    }

    private function at(int $hundredths): Score|NothingToMutate
    {
        return $this->exact->counted() + $this->stratum->mutants() === 0
            ? NothingToMutate::found()
            : Score::ofHundredths($hundredths);
    }

    private function hundredthsOf(Score|NothingToMutate $score): int
    {
        return $score instanceof Score ? $score->hundredths() : 0;
    }
}
