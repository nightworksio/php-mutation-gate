<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;

/** A mutant as a proof in a scope's ledger holds it, a kill as the proof keeps it, with that proof and scope. */
final readonly class Recorded
{
    private function __construct(private Mutant|ProvedKill $mutant, private Proof $proof, private Scope $scope)
    {
    }

    public static function in(Scope $scope, Proof $proof, Mutant|ProvedKill $mutant): self
    {
        return new self($mutant, $proof, $scope);
    }

    public function mutant(): Mutant|ProvedKill
    {
        return $this->mutant;
    }

    /** The scope whose ledger holds it. */
    public function scope(): Scope
    {
        return $this->scope;
    }

    /** The proof that holds it: its unit, and the run that established it. */
    public function proof(): Proof
    {
        return $this->proof;
    }
}
