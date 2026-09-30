<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function count;

use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
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
        private bool $cutShort,
        private KillMatrix $matrix,
        private RunAccount $account,
    ) {
    }

    /** A verdict over these trees, with no new-code set, no reach, no warning and no other failure. */
    public static function of(TreeVerdicts $trees): self
    {
        return new self(
            $trees,
            NewCodeVerdicts::none(),
            Reasons::of(),
            Warnings::none(),
            Failures::none(),
            cutShort: false,
            matrix: KillMatrix::none(),
            account: RunAccount::none(),
        );
    }

    /** This verdict, with the new-code sets a change-scoped run judged. */
    public function withNewCode(NewCodeVerdicts $newCode): self
    {
        return clone($this, ['newCode' => $newCode]);
    }

    /** This verdict, with the reasons for what the change reached. */
    public function withReach(Reasons $reach): self
    {
        return clone($this, ['reach' => $reach]);
    }

    public function withWarnings(Warnings $warnings): self
    {
        return clone($this, ['warnings' => $warnings]);
    }

    public function withFailures(Failures $failures): self
    {
        return clone($this, ['failures' => $failures]);
    }

    /** This verdict, with which tests cover each of its mutants and what each did with it in place (ADR-0014). */
    public function withMatrix(KillMatrix $matrix): self
    {
        return clone($this, ['matrix' => $matrix]);
    }

    /** This verdict, with its run's timings, cost, savings and the trend before it. */
    public function withAccount(RunAccount $account): self
    {
        return clone($this, ['account' => $account]);
    }

    /** This verdict, from a run a budget or a deadline stopped before it judged every mutant. */
    public function cutShort(): self
    {
        return clone($this, ['cutShort' => true]);
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

    /** The kill matrix; one of first killers that knows only each record's killers where the run gave none. */
    public function matrix(): KillMatrix
    {
        return $this->matrix;
    }

    /** Its run's timings, cost, savings and the trend before it; none of them where the flows gave none. */
    public function account(): RunAccount
    {
        return $this->account;
    }

    public function warnings(): Warnings
    {
        return $this->warnings;
    }

    public function failures(): Failures
    {
        return $this->failures;
    }

    /** Whether a budget or a deadline stopped the run before it judged every mutant. */
    public function wasCutShort(): bool
    {
        return $this->cutShort;
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
