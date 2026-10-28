<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Owner\Owner;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;

/**
 * One owner held to its own floor (ADR-0022): every mutant of each file
 * whose CODEOWNERS rule names the owner, in whatever group, carried results
 * among them, and the score they add up to against the floor
 * `owners.floors` declares. It has no baseline, and raises none.
 */
final readonly class OwnerFloorVerdict
{
    private function __construct(
        private Owner $owner,
        private Floor $floor,
        private JudgedMutants $mutants,
        private Uncovered $uncovered,
    ) {
    }

    public static function judged(Owner $owner, Floor $floor, JudgedMutants $mutants, Uncovered $uncovered): self
    {
        return new self($owner, $floor, $mutants, $uncovered);
    }

    public function owner(): Owner
    {
        return $this->owner;
    }

    public function floor(): Floor
    {
        return $this->floor;
    }

    public function mutants(): JudgedMutants
    {
        return $this->mutants;
    }

    public function uncovered(): Uncovered
    {
        return $this->uncovered;
    }

    /** Every mutant of the owner's code the score counts as not killed. */
    public function survivors(): Survivors
    {
        return $this->mutants->survivors($this->uncovered);
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
        return Judgement::of($this->floor, $this->score());
    }
}
