<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function count;

use NightWorksIO\MutationGate\Core\Reach\Reasons;

/**
 * What a run decided, and the one value every reporter renders: every tree
 * judged whole, the new-code sets, the reach and its reasons, the warnings,
 * and the failures no floor decides.
 */
final readonly class Verdict
{
    private function __construct(
        private TreeVerdicts $trees,
        private NewCodeVerdicts $newCode,
        private Reasons $reach,
        private Warnings $warnings,
        private Failures $failures,
    ) {
    }

    /** A verdict over these trees, with no new-code set, no reach, no warning and no other failure. */
    public static function of(TreeVerdicts $trees): self
    {
        return new self($trees, NewCodeVerdicts::none(), Reasons::of(), Warnings::none(), Failures::none());
    }

    /** This verdict, with the new-code sets a change-scoped run judged. */
    public function withNewCode(NewCodeVerdicts $newCode): self
    {
        return new self($this->trees, $newCode, $this->reach, $this->warnings, $this->failures);
    }

    /** This verdict, with the reasons for what the change reached. */
    public function withReach(Reasons $reach): self
    {
        return new self($this->trees, $this->newCode, $reach, $this->warnings, $this->failures);
    }

    public function withWarnings(Warnings $warnings): self
    {
        return new self($this->trees, $this->newCode, $this->reach, $warnings, $this->failures);
    }

    public function withFailures(Failures $failures): self
    {
        return new self($this->trees, $this->newCode, $this->reach, $this->warnings, $failures);
    }

    public function trees(): TreeVerdicts
    {
        return $this->trees;
    }

    /** The new-code sets; none where the run was not change-scoped. */
    public function newCode(): NewCodeVerdicts
    {
        return $this->newCode;
    }

    /** Every unit of every tree, tree by tree. */
    public function units(): JudgedUnits
    {
        $units = JudgedUnits::none();

        foreach ($this->trees as $tree) {
            $units = $units->and($tree->units());
        }

        return $units;
    }

    /** Every mutant of every tree, tree by tree. */
    public function mutants(): JudgedMutants
    {
        $mutants = JudgedMutants::none();

        foreach ($this->trees as $tree) {
            $mutants = $mutants->and($tree->mutants());
        }

        return $mutants;
    }

    /** Why the change reached what it did; none for a full run. */
    public function reach(): Reasons
    {
        return $this->reach;
    }

    public function warnings(): Warnings
    {
        return $this->warnings;
    }

    public function failures(): Failures
    {
        return $this->failures;
    }

    /** Failed when any tree or new-code set failed, or anything else did; passed otherwise. */
    public function judgement(): Judgement
    {
        $sets = [...$this->trees, ...$this->newCode];

        foreach ($sets as $set) {
            if ($set->judgement() === Judgement::Failed) {
                return Judgement::Failed;
            }
        }

        return count($this->failures) === 0 ? Judgement::Passed : Judgement::Failed;
    }
}
