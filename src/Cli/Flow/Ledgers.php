<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\Proving;
use NightWorksIO\MutationGate\Core\Proof\Access;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Records;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Port\ProofStore;

/**
 * The ledgers a run reads, the default branch's and its own scope's where it
 * has one of its own, and which of them it may write.
 */
final readonly class Ledgers
{
    private function __construct(
        private Scope|Detached $scope,
        private Scope $default,
        private Access $access,
        private Ledger $defaultBranch,
        private Ledger $own,
    ) {
    }

    public static function read(ProofStore $store, Standing $standing, Writing $writing): self
    {
        $scope = $standing->runOn()->scope();
        $default = $standing->defaultBranch();
        $ownScope = $scope instanceof Scope && ! $scope->equals($default);

        return new self(
            $scope,
            $default,
            Access::of($scope, $default, $writing),
            $store->read($default),
            $ownScope ? $store->read($scope) : Ledger::empty(),
        );
    }

    public function access(): Access
    {
        return $this->access;
    }

    /** The default branch's ledger, which a run on it also writes. */
    public function defaultBranch(): Ledger
    {
        return $this->defaultBranch;
    }

    /** The run's own scope's ledger; empty on the default branch, and for a run with no scope. */
    public function own(): Ledger
    {
        return $this->own;
    }

    /** The ledger of the scope the run writes, as it was read: the default branch's on it, and its own elsewhere. */
    public function written(): Ledger
    {
        return $this->scope instanceof Scope && $this->scope->equals($this->default)
            ? $this->defaultBranch
            : $this->own;
    }

    /**
     * Which tests killed each mutant and the mutants of each function, as
     * every ledger read learned it: the run's own scope's where both know one.
     */
    public function killers(): KillHistory
    {
        return $this->own->killers()->and($this->defaultBranch->killers());
    }

    /** Each unit's newest result, in any ledger read. */
    public function newest(): NewestProofs
    {
        return $this->own->and($this->defaultBranch)->proofs()->newest();
    }

    /** Every record the ledgers read hold of the mutants an id or a prefix of one names, the newest first. */
    public function records(IdPrefix $sought): Records
    {
        $records = Records::none($sought)->in($this->default, $this->defaultBranch);

        return $this->scope instanceof Scope && ! $this->scope->equals($this->default)
            ? $records->in($this->scope, $this->own)
            : $records;
    }

    /** How long each unit took, as every ledger read learned it. */
    public function timings(): Timings
    {
        return $this->defaultBranch->timings()->and($this->own->timings());
    }

    /**
     * Of these units, those a proof whose key still matches covers, and those
     * left to run. A ledger with no proof at the base the keys are built on
     * is not looked in, since none of its proofs can match.
     */
    public function proving(Units $units, Keys $keys, Digest $base): Proving
    {
        return Proving::of(
            $units,
            $keys,
            $this->defaultBranch->provesAt($base) ? $this->defaultBranch->proofs() : Proofs::none(),
            $this->own->provesAt($base) ? $this->own->proofs() : Proofs::none(),
        );
    }

    /** The newest commit of the run's own scope whose verdict passed; none for a run with no scope. */
    public function lastPassed(): Passed|CannotTell
    {
        return match (true) {
            ! $this->scope instanceof Scope => CannotTell::because(
                'The run has no ref of its own, so no commit of its own has passed.',
            ),
            $this->access->reads()->count() > 1 => $this->own->lastPassed(),
            default => $this->defaultBranch->lastPassed(),
        };
    }
}
