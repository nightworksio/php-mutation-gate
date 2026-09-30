<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\Baseline\Unlowered;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
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
        private Score|NothingToMutate|Unrecorded $base,
        private Lowered|Unlowered $lowering,
    ) {
    }

    public static function judged(
        Tree $tree,
        Floor|Unrecorded $baseline,
        JudgedUnits $units,
        JudgedMutants $mutants,
        Uncovered $uncovered,
    ): self {
        return new self($tree, $baseline, $units, $mutants, $uncovered, Unrecorded::floor(), Unlowered::floor());
    }

    /** This verdict, with the score the tree had on the base, for the change against it. */
    public function comparedWith(Score|NothingToMutate $base): self
    {
        return clone($this, ['base' => $base]);
    }

    /** This verdict, with why the baseline lowered the tree's floor, as its `lowered` says. */
    public function withLowering(Lowered $lowering): self
    {
        return clone($this, ['lowering' => $lowering]);
    }

    /**
     * This verdict, with its survivors of one cause marked with their
     * cluster, read from each file's source (ADR-0022, decision 15).
     *
     * @param ByPath<Contents> $sources each file of its survivors that can be read, by its path
     */
    public function clustered(ByPath $sources): self
    {
        return clone($this, ['mutants' => $this->mutants->clustered($this->uncovered, $sources)]);
    }

    /** This verdict, with what was found of each survivor (ADR-0025, decision 7). */
    public function found(Findings $findings): self
    {
        return clone($this, ['mutants' => $this->mutants->found($findings)]);
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

    /** Why the baseline lowered the tree's floor, where its entry says it did. */
    public function lowering(): Lowered|Unlowered
    {
        return $this->lowering;
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

    /** The score the tree had on the base; unrecorded where the base's results were not read. */
    public function base(): Score|NothingToMutate|Unrecorded
    {
        return $this->base;
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
    public function survivors(): Survivors
    {
        return $this->mutants->survivors($this->uncovered);
    }

    /**
     * The floor the baseline rises to: the score, where it is above the floor
     * the tree was held to, or where no floor holds it at all. A floor only
     * the declaration holds and the score merely meets is left unrecorded, and
     * so is the floor of a tree with an unjudged mutant, which the run did
     * not judge whole (ADR-0003, decision 4).
     */
    public function raised(): Floor|Unraised
    {
        $score = $this->score();
        $floor = $this->floor();
        $unjudged = $this->counts()->number(MutantJudgement::Unjudged) > 0;

        if ($unjudged || $score instanceof NothingToMutate || $floor instanceof Exempt) {
            return Unraised::floor();
        }

        if ($floor instanceof Floor && $floor->hundredths() >= $score->hundredths()) {
            return Unraised::floor();
        }

        return Floor::ofHundredths($score->hundredths());
    }
}
