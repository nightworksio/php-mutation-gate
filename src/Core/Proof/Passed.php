<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\Revision;

/**
 * The newest commit of a scope whose verdict passed: the check-run the verdict
 * reported under, and how many proofs of the scope's own ledger it used. A
 * passing run of another scope is trusted only where it used none of its own,
 * which its own code could have written.
 */
final readonly class Passed
{
    private function __construct(private Revision $commit, private string $check, private int $ownScopeProofs)
    {
    }

    public static function of(Revision $commit, string $check, int $ownScopeProofs): self
    {
        return new self($commit, $check, $ownScopeProofs);
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

    public function usedOwnScope(): bool
    {
        return $this->ownScopeProofs > 0;
    }
}
