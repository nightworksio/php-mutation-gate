<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;

/** How many mutants of a set came to each judgement, and the score they add up to. */
final readonly class Counts
{
    /** @param array<string, int> $numbers by judgement, every judgement present */
    private function __construct(private array $numbers)
    {
    }

    public static function of(JudgedMutants $mutants): self
    {
        $numbers = [];

        foreach (MutantJudgement::cases() as $judgement) {
            $numbers[$judgement->value] = 0;
        }

        foreach ($mutants as $mutant) {
            $numbers[$mutant->judgement()->value]++;
        }

        return new self($numbers);
    }

    public function number(MutantJudgement $judgement): int
    {
        return $this->numbers[$judgement->value];
    }

    /**
     * Killed ÷ (all − ignored − uncovered left out) × 100, truncated to two
     * decimals. A set with nothing left to count has no score.
     */
    public function score(Uncovered $uncovered): Score|NothingToMutate
    {
        $killed = 0;
        $counted = 0;

        foreach (MutantJudgement::cases() as $judgement) {
            $scoring = $judgement->scoring($uncovered);
            $killed += $scoring === Scoring::Killed ? $this->number($judgement) : 0;
            $counted += $scoring === Scoring::LeftOut ? 0 : $this->number($judgement);
        }

        return Score::of($killed, $counted);
    }
}
