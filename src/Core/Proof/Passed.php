<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\Revision;

/**
 * The newest commit of a scope whose verdict passed: the check-run the verdict
 * reported under, how many proofs of the scope's own ledger it used, and
 * whether its coverage map was measured against the map the scope keeps. A
 * passing run of another scope is trusted only where it used neither, which
 * its own code could have written (ADR-0007, decision 3).
 */
final readonly class Passed
{
    private function __construct(
        private Revision $commit,
        private string $check,
        private int $ownScopeProofs,
        private bool $ownScopeCoverage,
    ) {
    }

    public static function of(Revision $commit, string $check, int $ownScopeProofs): self
    {
        return new self($commit, $check, $ownScopeProofs, ownScopeCoverage: false);
    }

    /** This pass, whose coverage map was measured against the map its own scope keeps. */
    public function onOwnScopeCoverage(): self
    {
        return new self($this->commit, $this->check, $this->ownScopeProofs, ownScopeCoverage: true);
    }

    public function commit(): Revision
    {
        return $this->commit;
    }

    /** The name of the check-run the passing verdict reported under. */
    public function check(): string
    {
        return $this->check;
    }

    /** How many proofs of the scope's own ledger the passing verdict used. */
    public function ownScopeProofs(): int
    {
        return $this->ownScopeProofs;
    }

    /** Whether its coverage map was measured against the map its own scope keeps. */
    public function measuredOnOwnScope(): bool
    {
        return $this->ownScopeCoverage;
    }

    /** Whether it used anything of its own scope: a proof of its ledger, or the coverage map kept beside it. */
    public function usedOwnScope(): bool
    {
        return $this->ownScopeProofs > 0 || $this->ownScopeCoverage;
    }
}
