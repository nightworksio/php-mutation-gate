<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function count;

use NightWorksIO\MutationGate\Core\Owner\OwnerGroup;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/**
 * One owner group judged: every mutant of the files its CODEOWNERS rule
 * owns across the project, carried results among them, and the score they
 * add up to (ADR-0022). No floor holds it, so it passes, or has nothing to
 * mutate.
 */
final readonly class OwnerVerdict
{
    private function __construct(
        private OwnerGroup $group,
        private JudgedMutants $mutants,
        private Uncovered $uncovered,
    ) {
    }

    public static function judged(OwnerGroup $group, JudgedMutants $mutants, Uncovered $uncovered): self
    {
        return new self($group, $mutants, $uncovered);
    }

    public function group(): OwnerGroup
    {
        return $this->group;
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
        return Judgement::of(Undeclared::floor(), $this->score());
    }

    /** Whether a change touched the group: a mutant of it is on a line the change added or modified. */
    public function isTouched(): bool
    {
        return count($this->mutants->changed()) > 0;
    }
}
