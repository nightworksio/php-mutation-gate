<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use function array_slice;
use function count;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * A tree's sampled stratum split into the units its sample runs and those
 * it leaves out (ADR-0020): the stratum's share of the
 * tree's mutants and its candidates' last results size it, and the first
 * units the draw ranks fill it.
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

    /** The sample, holding this tree's draw, of a tree estimated to hold this many mutants in all. */
    public function into(Sample $sample, int $treeMutants, Candidate ...$candidates): Sample
    {
        $mutants = 0;
        $last = [];

        foreach ($candidates as $candidate) {
            $mutants += $candidate->mutants();
            $tally = $candidate->last();
            $last = $tally instanceof NotGiven ? $last : [...$last, $tally];
        }

        $ranked = $this->draw->ranked(...$candidates);
        $share = $treeMutants > 0 ? $mutants / $treeMutants : 0.0;
        $size = $this->size->units(count($ranked), $share, Spread::of(...$last));
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
}
