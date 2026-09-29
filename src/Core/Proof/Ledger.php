<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * What one scope has proved: its proofs, how long each unit took, and the
 * newest commit of the scope whose verdict passed.
 */
final readonly class Ledger
{
    private const string NEVER_PASSED = 'No commit of this scope has passed yet.';

    private function __construct(private Proofs $proofs, private Timings $timings, private Revision|CannotTell $passed)
    {
    }

    public static function empty(): self
    {
        return new self(Proofs::none(), Timings::none(), CannotTell::because(self::NEVER_PASSED));
    }

    public function withProof(Proof $proof): self
    {
        return new self($this->proofs->with($proof), $this->timings, $this->passed);
    }

    /** This ledger without the proof under a key, such as one a fresh result disagrees with. */
    public function withoutProof(Digest $key): self
    {
        return new self($this->proofs->without($key), $this->timings, $this->passed);
    }

    public function withTiming(Timing $timing): self
    {
        return new self($this->proofs, $this->timings->with($timing), $this->passed);
    }

    /** This ledger, with what a finished shard measured, each unit keeping its newest timing. */
    public function withTimings(Timings $timings): self
    {
        return new self($this->proofs, $this->timings->and($timings), $this->passed);
    }

    /** This ledger, with a commit whose verdict passed, replacing the one held. */
    public function withPassed(Revision $commit): self
    {
        return new self($this->proofs, $this->timings, $commit);
    }

    /**
     * This ledger and another scope's, read together: this one's proofs first,
     * so a key both prove keeps this one's, each unit's newest timing, and this
     * one's passing commit.
     */
    public function and(self $other): self
    {
        return new self(
            Proofs::of(...$this->proofs, ...$other->proofs),
            $this->timings->and($other->timings),
            $this->passed,
        );
    }

    /** This ledger with timings only for these units, which are the ones that still exist. */
    public function keepingTimingsOf(Paths $units): self
    {
        return new self($this->proofs, $this->timings->onlyFor($units), $this->passed);
    }

    public function proofs(): Proofs
    {
        return $this->proofs;
    }

    public function timings(): Timings
    {
        return $this->timings;
    }

    /** The newest commit of this scope whose verdict passed: the `last-passed` base. */
    public function lastPassed(): Revision|CannotTell
    {
        return $this->passed;
    }
}
