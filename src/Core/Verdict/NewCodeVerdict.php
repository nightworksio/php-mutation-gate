<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Tree\Package;

/**
 * The mutants on the lines a change added or modified in one package, held
 * to the floor for new code. A change with no mutable lines there has an
 * empty set, which passes and says so.
 */
final readonly class NewCodeVerdict
{
    private function __construct(
        private Package $package,
        private Floor $floor,
        private JudgedMutants $mutants,
        private Uncovered $uncovered,
    ) {
    }

    public static function judged(Package $package, Floor $floor, JudgedMutants $mutants, Uncovered $uncovered): self
    {
        return new self($package, $floor, $mutants, $uncovered);
    }

    public function package(): Package
    {
        return $this->package;
    }

    public function floor(): Floor
    {
        return $this->floor;
    }

    public function mutants(): JudgedMutants
    {
        return $this->mutants;
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

    /** The mutants the score counts as not killed, in reported order. */
    public function survivors(): Survivors
    {
        return $this->mutants->survivors($this->uncovered);
    }
}
