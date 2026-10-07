<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistories;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Order\KillHistory;

/**
 * What one scope has proved: its proofs, how long each unit took, the bases
 * the runs that wrote it keyed their units at, the most recent first, what it
 * holds of its runs ({@see ScopeRuns}), which tests killed its mutants first,
 * and what it learned of each static analyser.
 */
final readonly class Ledger
{
    private function __construct(
        private Proofs $proofs,
        private Timings $timings,
        private Bases $bases,
        private ScopeRuns $runs,
        private KillHistory $killers,
        private AnalyserHistories $analysers,
    ) {
    }

    public static function empty(): self
    {
        return new self(
            Proofs::none(),
            Timings::none(),
            Bases::none(),
            ScopeRuns::none(),
            KillHistory::none(),
            AnalyserHistories::none(),
        );
    }

    public function withProof(Proof $proof): self
    {
        return clone($this, ['proofs' => $this->proofs->with($proof)]);
    }

    /**
     * This ledger, with these proofs; a key it proves already keeps its own
     * proof, unless only theirs recorded every killer.
     */
    public function withProofs(Proofs $proofs): self
    {
        return clone($this, ['proofs' => Proofs::of(...$this->proofs, ...$proofs)]);
    }

    /** This ledger without the proof under a key, such as one a fresh result disagrees with. */
    public function withoutProof(Digest $key): self
    {
        return clone($this, ['proofs' => $this->proofs->without($key)]);
    }

    /** This ledger, with what a finished shard measured, each unit keeping its newest timing. */
    public function withTimings(Timings $timings): self
    {
        return clone($this, ['timings' => $this->timings->and($timings)]);
    }

    /** This ledger, written by a run that keyed its units at this base, which it has now seen most recently. */
    public function atBase(Digest $base): self
    {
        return clone($this, ['bases' => $this->bases->seen($base)]);
    }

    /** This ledger, with these bases seen after the ones it holds. */
    public function withBases(Bases $bases): self
    {
        return clone($this, ['bases' => $this->bases->and($bases)]);
    }

    /** This ledger, with what it holds of its runs, replacing what it held. */
    public function withRuns(ScopeRuns $runs): self
    {
        return clone($this, ['runs' => $runs]);
    }

    /** This ledger, with the kill history a run learned, replacing the one held. */
    public function withKillers(KillHistory $killers): self
    {
        return clone($this, ['killers' => $killers]);
    }

    /**
     * This ledger and another scope's, read together: this one's proofs first,
     * so a key both prove keeps this one's, each unit's newest timing, this
     * one's bases before the other's, what this one holds of its runs, and
     * where both know who killed a mutant or a function's mutants, this
     * one's, and where both learned of an analyser, this one's.
     */
    public function and(self $other): self
    {
        return new self(
            Proofs::of(...$this->proofs, ...$other->proofs),
            $this->timings->and($other->timings),
            $this->bases->and($other->bases),
            $this->runs,
            $this->killers->and($other->killers),
            $this->analysers->and($other->analysers),
        );
    }

    /** This ledger, with what a run learned of the static analysers, replacing what it held. */
    public function withAnalysers(AnalyserHistories $analysers): self
    {
        return clone($this, ['analysers' => $analysers]);
    }

    /** This ledger with timings only for these units, which are the ones that still exist. */
    public function keepingTimingsOf(Paths $units): self
    {
        return clone($this, ['timings' => $this->timings->onlyFor($units)]);
    }

    /** This ledger with the kill history of the functions in these files only, which are the ones that still exist. */
    public function keepingKillersIn(Paths $files): self
    {
        return clone($this, ['killers' => $this->killers->onlyIn($files)]);
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

    /** Which tests killed this scope's mutants first, and its functions' mutants. */
    public function killers(): KillHistory
    {
        return $this->killers;
    }

    /** What this scope's runs learned of each static analyser: its rejection rates and how long its checks take. */
    public function analysers(): AnalyserHistories
    {
        return $this->analysers;
    }
}
