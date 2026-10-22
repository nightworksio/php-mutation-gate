<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use function array_slice;
use function count;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * A tree's sampled stratum split into the units its sample runs and those
 * it leaves out (ADR-0020): the stratum is the tree's units no run knows
 * exactly, its share of the tree's mutants and its units' last results
 * size it, and the first units the draw ranks fill it.
 */
final readonly class Drawing
{
    private function __construct(private Draw $draw, private SampleSize $size)
    {
    }

    public static function of(Draw $draw, SampleSize $size): self
    {
        return new self($draw, $size);
    }

    /**
     * The sample, holding the draw of a tree whose units are these
     * candidates, of those of them no run knows exactly.
     */
    public function into(Sample $sample, Units $unknown, Candidate ...$tree): Sample
    {
        $stratum = [];

        foreach ($tree as $candidate) {
            $stratum = $unknown->has($candidate->unit()->path()) ? [...$stratum, $candidate] : $stratum;
        }

        $ranked = $this->draw->ranked(...$stratum);
        $size = $this->size->units(
            count($ranked),
            $this->mutantsOf(...$stratum),
            $this->mutantsOf(...$tree),
            $this->lastOf(...$stratum),
        );
        $sampled = Units::none();
        $unsampled = Unsampled::none();

        foreach (array_slice($ranked, 0, $size) as $candidate) {
            $sampled = $sampled->with($candidate->unit());
        }

        foreach (array_slice($ranked, $size) as $candidate) {
            $unsampled = $unsampled->with($candidate);
        }

        return $sample->holding($sampled, $unsampled);
    }

    /** The mutants these candidates are estimated to hold. */
    private function mutantsOf(Candidate ...$candidates): int
    {
        $mutants = 0;

        foreach ($candidates as $candidate) {
            $mutants += $candidate->mutants();
        }

        return $mutants;
    }

    /** The last results of those of these candidates that have one. */
    private function lastOf(Candidate ...$candidates): Spread
    {
        $last = [];

        foreach ($candidates as $candidate) {
            $tally = $candidate->last();
            $last = $tally instanceof NotGiven ? $last : [...$last, $tally];
        }

        return Spread::of(...$last);
    }
}
