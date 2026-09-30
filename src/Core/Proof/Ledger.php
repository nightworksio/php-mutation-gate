<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * What one scope has proved: its proofs, how long each unit took, the bases
 * the runs that wrote it keyed their units at, the most recent first, and the
 * newest commit of the scope whose verdict passed.
 */
final readonly class Ledger
{
    private const string NEVER_PASSED = 'No commit of this scope has passed yet.';

    private function __construct(
        private Proofs $proofs,
        private Timings $timings,
        private Bases $bases,
        private Passed|CannotTell $passed,
    ) {
    }

    public static function empty(): self
    {
        return new self(Proofs::none(), Timings::none(), Bases::none(), CannotTell::because(self::NEVER_PASSED));
    }

    public function withProof(Proof $proof): self
    {
        return new self($this->proofs->with($proof), $this->timings, $this->bases, $this->passed);
    }

    /** This ledger, with these proofs; a key it proves already keeps its own proof. */
    public function withProofs(Proofs $proofs): self
    {
        return new self(Proofs::of(...$this->proofs, ...$proofs), $this->timings, $this->bases, $this->passed);
    }

    /** This ledger without the proof under a key, such as one a fresh result disagrees with. */
    public function withoutProof(Digest $key): self
    {
        return new self($this->proofs->without($key), $this->timings, $this->bases, $this->passed);
    }

    public function withTiming(Timing $timing): self
    {
        return new self($this->proofs, $this->timings->with($timing), $this->bases, $this->passed);
    }

    /** This ledger, with what a finished shard measured, each unit keeping its newest timing. */
    public function withTimings(Timings $timings): self
    {
        return new self($this->proofs, $this->timings->and($timings), $this->bases, $this->passed);
    }

    /** This ledger, written by a run that keyed its units at this base, which it has now seen most recently. */
    public function atBase(Digest $base): self
    {
        return new self($this->proofs, $this->timings, $this->bases->seen($base), $this->passed);
    }

    /** This ledger, with these bases seen after the ones it holds. */
    public function withBases(Bases $bases): self
    {
        return new self($this->proofs, $this->timings, $this->bases->and($bases), $this->passed);
    }

    /** This ledger, with the newest commit whose verdict passed, replacing the one held. */
    public function withPassed(Passed $passed): self
    {
        return new self($this->proofs, $this->timings, $this->bases, $passed);
    }

    /**
     * This ledger and another scope's, read together: this one's proofs first,
     * so a key both prove keeps this one's, each unit's newest timing, this
     * one's bases before the other's, and this one's passing commit.
     */
    public function and(self $other): self
    {
        return new self(
            Proofs::of(...$this->proofs, ...$other->proofs),
            $this->timings->and($other->timings),
            $this->bases->and($other->bases),
            $this->passed,
        );
    }

    /** This ledger with timings only for these units, which are the ones that still exist. */
    public function keepingTimingsOf(Paths $units): self
    {
        return new self($this->proofs, $this->timings->onlyFor($units), $this->bases, $this->passed);
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

    /**
     * Whether any proof was established at this base. A key can only match a
     * proof of the same base, so where none was, no key of a run at it can.
     */
    public function provesAt(Digest $base): bool
    {
        foreach ($this->proofs as $proof) {
            if ($proof->run()->base()->value() === $base->value()) {
                return true;
            }
        }

        return false;
    }

    /** The newest commit of this scope whose verdict passed: the `last-passed` base. */
    public function lastPassed(): Passed|CannotTell
    {
        return $this->passed;
    }
}
