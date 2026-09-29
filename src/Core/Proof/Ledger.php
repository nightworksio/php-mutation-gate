<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;

use function sprintf;

/**
 * What one scope has proved: its proofs, how long each unit took, and the
 * newest commit on each branch whose verdict passed.
 */
final readonly class Ledger
{
    /** @param array<string, Revision> $passed the newest passing commit, by branch */
    private function __construct(private Proofs $proofs, private Timings $timings, private array $passed)
    {
    }

    public static function empty(): self
    {
        return new self(Proofs::none(), Timings::none(), []);
    }

    public function withProof(Proof $proof): self
    {
        return new self($this->proofs->with($proof), $this->timings, $this->passed);
    }

    public function withTiming(Timing $timing): self
    {
        return new self($this->proofs, $this->timings->with($timing), $this->passed);
    }

    /** This ledger, with a commit on a branch whose verdict passed, replacing any older one. */
    public function withPassed(Revision $branch, Revision $commit): self
    {
        $passed = $this->passed;
        $passed[$branch->name()] = $commit;

        return new self($this->proofs, $this->timings, $passed);
    }

    public function proofs(): Proofs
    {
        return $this->proofs;
    }

    public function timings(): Timings
    {
        return $this->timings;
    }

    public function lastPassedOn(Revision $branch): Revision|CannotTell
    {
        return array_key_exists($branch->name(), $this->passed)
            ? $this->passed[$branch->name()]
            : CannotTell::because(sprintf('No commit on %s has passed yet.', $branch->name()));
    }
}
