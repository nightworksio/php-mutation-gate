<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unraised;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;

/**
 * The floor a ratcheted set is held to, a tree or a package's security set:
 * the higher of the one declared for it and the one the baseline holds, and
 * the floor its score raises the baseline to (ADR-0003, decisions 6 and 9).
 */
final readonly class HeldFloor
{
    private function __construct(private Floor|Exempt|Undeclared $declared, private Floor|Unrecorded $baseline)
    {
    }

    public static function of(Floor|Exempt|Undeclared $declared, Floor|Unrecorded $baseline): self
    {
        return new self($declared, $baseline);
    }

    /** The higher of the declared floor and the baseline's; undeclared when neither holds one. */
    public function floor(): Floor|Exempt|Undeclared
    {
        if ($this->declared instanceof Exempt || $this->baseline instanceof Unrecorded) {
            return $this->declared;
        }

        if ($this->declared instanceof Undeclared || $this->baseline->hundredths() > $this->declared->hundredths()) {
            return $this->baseline;
        }

        return $this->declared;
    }

    /**
     * The floor the baseline rises to: the score, where it is above the floor
     * the set was held to, or where no floor holds it at all. A floor only
     * the declaration holds and the score merely meets is left unrecorded, and
     * so is the floor of a set with an unjudged mutant, which the run did not
     * judge whole (ADR-0003, decision 4).
     */
    public function raisedBy(Counts $counts, Uncovered $uncovered): Floor|Unraised
    {
        $score = $counts->score($uncovered);
        $floor = $this->floor();
        $unjudged = $counts->number(MutantJudgement::Unjudged) > 0;

        if ($unjudged || $score instanceof NothingToMutate || $floor instanceof Exempt) {
            return Unraised::floor();
        }

        if ($floor instanceof Floor && $floor->hundredths() >= $score->hundredths()) {
            return Unraised::floor();
        }

        return Floor::ofHundredths($score->hundredths());
    }
}
