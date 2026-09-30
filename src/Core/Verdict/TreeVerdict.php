<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unraised;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Tree;

/**
 * One tree judged whole: every unit in it, whether its result was run,
 * proved or carried, every mutant behind the score, and the floor the score
 * was held to, which is the higher of the declared floor and the baseline's.
 */
final readonly class TreeVerdict
{
    private function __construct(
        private Tree $tree,
        private Floor|Unrecorded $baseline,
        private JudgedUnits $units,
        private JudgedMutants $mutants,
        private Uncovered $uncovered,
    ) {
    }

    public static function judged(
        Tree $tree,
        Floor|Unrecorded $baseline,
        JudgedUnits $units,
        JudgedMutants $mutants,
        Uncovered $uncovered,
    ): self {
        return new self($tree, $baseline, $units, $mutants, $uncovered);
    }

    /** The tree, with the floor it declares. */
    public function tree(): Tree
    {
        return $this->tree;
    }

    /** The floor the committed baseline holds for this tree. */
    public function baseline(): Floor|Unrecorded
    {
        return $this->baseline;
    }

    /** The higher of the declared floor and the baseline's; undeclared when neither holds one. */
    public function floor(): Floor|Exempt|Undeclared
    {
        $declared = $this->tree->declared();

        if ($declared instanceof Exempt || $this->baseline instanceof Unrecorded) {
            return $declared;
        }

        if ($declared instanceof Undeclared || $this->baseline->hundredths() > $declared->hundredths()) {
            return $this->baseline;
        }

        return $declared;
    }

    public function units(): JudgedUnits
    {
        return $this->units;
    }

    public function mutants(): JudgedMutants
    {
        return $this->mutants;
    }

    /** How uncovered mutants counted in the score. */
    public function uncovered(): Uncovered
    {
        return $this->uncovered;
    }

    public function counts(): Counts
    {
        return $this->mutants->counts();
    }

    public function score(): Score|NothingToMutate
    {
        return $this->counts()->score($this->uncovered);
    }

    public function judgement(): Judgement
    {
        return Judgement::of($this->floor(), $this->score());
    }

    /** The mutants the score counts as not killed, those on changed lines first. */
    public function survivors(): JudgedMutants
    {
        return $this->mutants->survivors($this->uncovered);
    }

    /**
     * The floor the baseline rises to: the score, where it is above the floor
     * the tree was held to, or where no floor holds it at all. A floor only
     * the declaration holds and the score merely meets is left unrecorded.
     */
    public function raised(): Floor|Unraised
    {
        $score = $this->score();
        $floor = $this->floor();

        if ($score instanceof NothingToMutate || $floor instanceof Exempt) {
            return Unraised::floor();
        }

        if ($floor instanceof Floor && $floor->hundredths() >= $score->hundredths()) {
            return Unraised::floor();
        }

        return Floor::ofHundredths($score->hundredths());
    }
}
