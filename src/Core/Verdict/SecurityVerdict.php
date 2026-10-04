<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\Baseline\Unlowered;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unraised;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;

/**
 * One package's security set judged whole: every security mutant of its
 * trees, which also count in their trees, held to the higher of the floor
 * declared for it and the baseline's (ADR-0021, decisions 16 and 17).
 */
final readonly class SecurityVerdict
{
    /** How a security set is named beside the trees: by its package's path. */
    public const string SET = 'security set of %s';

    private function __construct(
        private Package $package,
        private Floor|Undeclared $declared,
        private Floor|Unrecorded $baseline,
        private JudgedMutants $mutants,
        private Uncovered $uncovered,
        private Lowered|Unlowered $lowering,
    ) {
    }

    public static function judged(
        Package $package,
        Floor|Undeclared $declared,
        Floor|Unrecorded $baseline,
        JudgedMutants $mutants,
        Uncovered $uncovered,
    ): self {
        return new self($package, $declared, $baseline, $mutants, $uncovered, Unlowered::floor());
    }

    /** This verdict, with why the baseline lowered the set's floor, as its `lowered` says. */
    public function withLowering(Lowered $lowering): self
    {
        return clone($this, ['lowering' => $lowering]);
    }

    public function package(): Package
    {
        return $this->package;
    }

    /** The floor the package's `securityFloor`, or else `security.floor`, declares. */
    public function declared(): Floor|Undeclared
    {
        return $this->declared;
    }

    /** The floor the committed baseline holds for the set. */
    public function baseline(): Floor|Unrecorded
    {
        return $this->baseline;
    }

    /** Why the baseline lowered the set's floor, where its entry says it did. */
    public function lowering(): Lowered|Unlowered
    {
        return $this->lowering;
    }

    /** The higher of the declared floor and the baseline's; undeclared when neither holds one. */
    public function floor(): Floor|Undeclared
    {
        $floor = HeldFloor::of($this->declared, $this->baseline)->floor();

        return $floor instanceof Floor ? $floor : Undeclared::floor();
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
        return Judgement::of($this->floor(), $this->score());
    }

    /** The security mutants the score counts as not killed, those on changed lines first. */
    public function survivors(): Survivors
    {
        return $this->mutants->survivors($this->uncovered);
    }

    /** The floor the baseline rises to (ADR-0003, decision 6). */
    public function raised(): Floor|Unraised
    {
        return HeldFloor::of($this->declared, $this->baseline)->raisedBy($this->counts(), $this->uncovered);
    }
}
