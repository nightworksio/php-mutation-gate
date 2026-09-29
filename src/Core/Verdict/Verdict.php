<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Mutant\Mutants;

/**
 * What a run decided: every tree with its score and judgement, every mutant
 * behind them, and the warnings. Every reporter renders this one value.
 */
final readonly class Verdict
{
    private function __construct(private TreeVerdicts $trees, private Mutants $mutants, private Warnings $warnings) {}

    public static function of(TreeVerdicts $trees, Mutants $mutants, Warnings $warnings): self
    {
        return new self($trees, $mutants, $warnings);
    }

    public function trees(): TreeVerdicts
    {
        return $this->trees;
    }

    public function mutants(): Mutants
    {
        return $this->mutants;
    }

    public function warnings(): Warnings
    {
        return $this->warnings;
    }

    /** Failed when any tree failed; passed otherwise, with no tree at all included. */
    public function judgement(): Judgement
    {
        foreach ($this->trees as $tree) {
            if ($tree->judgement() === Judgement::Failed) {
                return Judgement::Failed;
            }
        }

        return Judgement::Passed;
    }
}
