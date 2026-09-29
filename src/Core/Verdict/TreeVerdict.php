<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Tree\Tree;

/** One tree, the score its mutants reached, and whether that met its floor. */
final readonly class TreeVerdict
{
    private function __construct(
        private Tree $tree,
        private Score|NothingToMutate $score,
        private Judgement $judgement,
    ) {
    }

    public static function of(Tree $tree, Score|NothingToMutate $score, Judgement $judgement): self
    {
        return new self($tree, $score, $judgement);
    }

    public function tree(): Tree
    {
        return $this->tree;
    }

    public function score(): Score|NothingToMutate
    {
        return $this->score;
    }

    public function judgement(): Judgement
    {
        return $this->judgement;
    }
}
