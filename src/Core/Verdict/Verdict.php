<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_any;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Reach\Reasons;

/**
 * What a run decided, and the one value every reporter renders: every tree
 * judged whole, the new-code sets, the reach and its reasons, the warnings,
 * the failures no floor decides, and anything that kept the run from judging.
 */
final readonly class Verdict
{
    private function __construct(
        private TreeVerdicts $trees,
        private HeldSets $sets,
        private Reasons $reach,
        private Warnings $warnings,
        private Failures $failures,
        private bool $cutShort,
        private KillMatrix $matrix,
        private RunAccount $account,
        private Obstacles $obstacles,
        private HeldTo $heldTo,
    ) {
    }

    /**
     * A verdict over these trees, held to the floors this says, with no
     * new-code set, no reach, no warning and no other failure.
     */
    public static function of(TreeVerdicts $trees, HeldTo $heldTo = HeldTo::TreesAndNewCode): self
    {
        return new self(
            $trees,
            HeldSets::none(),
            Reasons::of(),
            Warnings::none(),
            Failures::none(),
            cutShort: false,
            matrix: KillMatrix::none(),
            account: RunAccount::none(),
            obstacles: Obstacles::none(),
            heldTo: $heldTo,
        );
    }

    /** This verdict, with the sets it judges beside its trees: a change-scoped run's new code, and security. */
    public function withSets(HeldSets $sets): self
    {
        return new self(
            $this->trees,
            $sets,
            $this->reach,
            $this->warnings,
            $this->failures,
            $this->cutShort,
            $this->matrix,
            $this->account,
            $this->obstacles,
            $this->heldTo,
        );
    }

    /** This verdict, with the reasons for what the change reached. */
    public function withReach(Reasons $reach): self
    {
        return new self(
            $this->trees,
            $this->sets,
            $reach,
            $this->warnings,
            $this->failures,
            $this->cutShort,
            $this->matrix,
            $this->account,
            $this->obstacles,
            $this->heldTo,
        );
    }

    public function withWarnings(Warnings $warnings): self
    {
        return new self(
            $this->trees,
            $this->sets,
            $this->reach,
            $warnings,
            $this->failures,
            $this->cutShort,
            $this->matrix,
            $this->account,
            $this->obstacles,
            $this->heldTo,
        );
    }

    public function withFailures(Failures $failures): self
    {
        return new self(
            $this->trees,
            $this->sets,
            $this->reach,
            $this->warnings,
            $failures,
            $this->cutShort,
            $this->matrix,
            $this->account,
            $this->obstacles,
            $this->heldTo,
        );
    }

    /** This verdict, with which tests cover each of its mutants and what each did with it in place (ADR-0014). */
    public function withMatrix(KillMatrix $matrix): self
    {
        return new self(
            $this->trees,
            $this->sets,
            $this->reach,
            $this->warnings,
            $this->failures,
            $this->cutShort,
            $matrix,
            $this->account,
            $this->obstacles,
            $this->heldTo,
        );
    }

    /** This verdict, with its run's timings, cost, savings and the trend before it. */
    public function withAccount(RunAccount $account): self
    {
        return new self(
            $this->trees,
            $this->sets,
            $this->reach,
            $this->warnings,
            $this->failures,
            $this->cutShort,
            $this->matrix,
            $account,
            $this->obstacles,
            $this->heldTo,
        );
    }

    /** This verdict, from a run that met something it cannot judge honestly past, which decides it. */
    public function withCannotJudge(CannotJudge $why): self
    {
        return new self(
            $this->trees,
            $this->sets,
            $this->reach,
            $this->warnings,
            $this->failures,
            $this->cutShort,
            $this->matrix,
            $this->account,
            $this->obstacles->with($why),
            $this->heldTo,
        );
    }

    /** This verdict, from a run a budget or a deadline stopped before it judged every mutant. */
    public function cutShort(): self
    {
        return new self(
            $this->trees,
            $this->sets,
            $this->reach,
            $this->warnings,
            $this->failures,
            cutShort: true,
            matrix: $this->matrix,
            account: $this->account,
            obstacles: $this->obstacles,
            heldTo: $this->heldTo,
        );
    }

    public function trees(): TreeVerdicts
    {
        return $this->trees;
    }

    /** The sets it judges beside its trees: the new-code sets, and each package's security set. */
    public function sets(): HeldSets
    {
        return $this->sets;
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

    /**
     * What kept the run from judging honestly, in the order it was met, then
     * each set of tests that fails without any mutant; none for a judged run.
     */
    public function obstacles(): Obstacles
    {
        return $this->obstacles->failing(UnmutatedFailures::in($this->trees->mutants()));
    }

    /** Whether a budget or a deadline stopped the run before it judged every mutant. */
    public function wasCutShort(): bool
    {
        return $this->cutShort;
    }

    /**
     * Cannot judge when anything kept the run from judging; failed when any
     * tree, security set, new-code set or owner floor it holds failed, or
     * anything else did; passed otherwise.
     */
    public function judgement(): Judgement
    {
        $held = [
            ...$this->heldTo->holdsTrees() ? $this->trees : [],
            ...$this->heldTo->holdsNewCode() ? $this->sets->newCode() : [],
            ...$this->heldTo->holdsSecurity() ? $this->sets->security() : [],
            ...$this->heldTo->holdsTrees() ? $this->sets->ownerFloors() : [],
        ];
        $failed = array_any(
            $held,
            static fn(TreeVerdict|NewCodeVerdict|SecurityVerdict|OwnerFloorVerdict $set): bool
                => $set->judgement() === Judgement::Failed,
        );

        return match (true) {
            count($this->obstacles()) > 0 => Judgement::CannotJudge,
            $failed || count($this->failures) > 0 => Judgement::Failed,
            default => Judgement::Passed,
        };
    }
}
