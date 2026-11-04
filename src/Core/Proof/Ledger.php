<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistories;
use NightWorksIO\MutationGate\Core\Cost\MutatorTallies;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Pruning\Survival;

/**
 * What one scope has proved: its proofs, how long each unit took, the bases
 * the runs that wrote it keyed their units at, the most recent first, what it
 * holds of its runs ({@see ScopeRuns}), and what its runs learned beside its
 * proofs ({@see Learned}).
 */
final readonly class Ledger
{
    private function __construct(
        private Proofs $proofs,
        private Timings $timings,
        private Bases $bases,
        private ScopeRuns $runs,
        private Learned $learned,
    ) {
    }

    public static function empty(): self
    {
        return new self(
            Proofs::none(),
            Timings::none(),
            Bases::none(),
            ScopeRuns::none(),
            Learned::none(),
        );
    }

    public function withProof(Proof $proof): self
    {
        return new self(
            $this->proofs->with($proof),
            $this->timings,
            $this->bases,
            $this->runs,
            $this->learned,
        );
    }

    /**
     * This ledger, with these proofs; a key it proves already keeps its own
     * proof, unless only theirs recorded every killer.
     */
    public function withProofs(Proofs $proofs): self
    {
        return new self(
            Proofs::of(...$this->proofs, ...$proofs),
            $this->timings,
            $this->bases,
            $this->runs,
            $this->learned,
        );
    }

    /** This ledger without the proof under a key, such as one a fresh result disagrees with. */
    public function withoutProof(Digest $key): self
    {
        return new self(
            $this->proofs->without($key),
            $this->timings,
            $this->bases,
            $this->runs,
            $this->learned,
        );
    }

    /** This ledger, with what a finished shard measured, each unit keeping its newest timing. */
    public function withTimings(Timings $timings): self
    {
        return new self(
            $this->proofs,
            $this->timings->and($timings),
            $this->bases,
            $this->runs,
            $this->learned,
        );
    }

    /** This ledger, written by a run that keyed its units at this base, which it has now seen most recently. */
    public function atBase(Digest $base): self
    {
        return new self(
            $this->proofs,
            $this->timings,
            $this->bases->seen($base),
            $this->runs,
            $this->learned,
        );
    }

    /** This ledger, with these bases seen after the ones it holds. */
    public function withBases(Bases $bases): self
    {
        return new self(
            $this->proofs,
            $this->timings,
            $this->bases->and($bases),
            $this->runs,
            $this->learned,
        );
    }

    /** This ledger, with what it holds of its runs, replacing what it held. */
    public function withRuns(ScopeRuns $runs): self
    {
        return new self(
            $this->proofs,
            $this->timings,
            $this->bases,
            $runs,
            $this->learned,
        );
    }

    /**
     * This ledger, with what a run learned, replacing what it held of that
     * kind: the kill history, what the static analysers came to, what each
     * mutator's newest mutants came to, or what each mutator's mutants cost
     * and found.
     */
    public function withLearned(KillHistory|AnalyserHistories|Survival|MutatorTallies $learned): self
    {
        return new self(
            $this->proofs,
            $this->timings,
            $this->bases,
            $this->runs,
            $this->learned->with($learned),
        );
    }

    /**
     * This ledger and another scope's, read together: this one's proofs first,
     * so a key both prove keeps this one's, each unit's newest timing, this
     * one's bases before the other's, what this one holds of its runs, and
     * where both learned of the same thing, this one's.
     */
    public function and(self $other): self
    {
        return new self(
            Proofs::of(...$this->proofs, ...$other->proofs),
            $this->timings->and($other->timings),
            $this->bases->and($other->bases),
            $this->runs,
            $this->learned->and($other->learned),
        );
    }

    /** This ledger with timings only for these units, which are the ones that still exist. */
    public function keepingTimingsOf(Paths $units): self
    {
        return new self(
            $this->proofs,
            $this->timings->onlyFor($units),
            $this->bases,
            $this->runs,
            $this->learned,
        );
    }

    /** This ledger with the kill history of the functions in these files only, which are the ones that still exist. */
    public function keepingKillersIn(Paths $files): self
    {
        return new self(
            $this->proofs,
            $this->timings,
            $this->bases,
            $this->runs,
            $this->learned->keepingKillersIn($files),
        );
    }

    public function proofs(): Proofs
    {
        return $this->proofs;
    }

    public function timings(): Timings
    {
        return $this->timings;
    }

    /** The bases the runs that wrote this ledger keyed their units at, the most recently seen first. */
    public function bases(): Bases
    {
        return $this->bases;
    }

    /** What this ledger holds of its scope's runs: the `last-passed` and `last-run` bases. */
    public function runs(): ScopeRuns
    {
        return $this->runs;
    }

    /** What this scope's runs learned beside its proofs. */
    public function learned(): Learned
    {
        return $this->learned;
    }
}
