<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/**
 * The proofs a run read from the packs of `outbreak cache` it trusts
 * (ADR-0131): those trusted writers wrote, which prove after the default
 * branch's ledger, and those a branch wrote, which prove after the run's own
 * scope's and count as its own scope's proofs do; and those `outbreak serve`
 * keeps, which prove after a branch's.
 *
 * @internal
 */
final readonly class CachedProofs
{
    private function __construct(private Proofs $trusted, private Proofs $branch)
    {
    }

    public static function none(): self
    {
        return new self(Proofs::none(), Proofs::none());
    }

    /** These, and the proofs of a pack of this class besides, each after those already held under its key. */
    public function with(CacheClass $class, Proofs $proofs): self
    {
        return $class === CacheClass::Trusted
            ? new self($this->trusted->before($proofs), $this->branch)
            : new self($this->trusted, $this->branch->before($proofs));
    }

    /**
     * These, and the proofs `outbreak serve` keeps besides (ADR-0133), which
     * prove after a branch's, as the run's own scope's do.
     */
    public function serving(Proofs $served): self
    {
        return new self($this->trusted, $this->branch->before($served));
    }

    /** The proofs trusted writers wrote. */
    public function trusted(): Proofs
    {
        return $this->trusted;
    }

    /** The proofs a branch of the repository wrote. */
    public function branch(): Proofs
    {
        return $this->branch;
    }

    /** How many proofs it holds. */
    public function count(): int
    {
        return $this->trusted->count() + $this->branch->count();
    }
}
