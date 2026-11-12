<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * The newest commit of a scope whose verdict passed: the check-run the verdict
 * reported under, how many proofs of the scope's own ledger it used, whether its
 * coverage map was measured against the map the scope keeps, when the verdict
 * passed and the release of the gate that judged it, and the units whose results
 * it took from its own scope's proofs, where the record says so. A passing run
 * of another scope is trusted only where it used neither, which its own code
 * could have written (ADR-0007).
 */
final readonly class Passed
{
    private function __construct(
        private Revision $commit,
        private string $check,
        private int $ownScopeProofs,
        private bool $ownScopeCoverage,
        private Instant|NotGiven $at,
        private GateRelease $gate,
        private Paths|NotGiven $ownScopeUnits,
    ) {
    }

    public static function of(Revision $commit, string $check, int $ownScopeProofs): self
    {
        return new self(
            $commit,
            $check,
            $ownScopeProofs,
            ownScopeCoverage: false,
            at: NotGiven::value(),
            gate: GateRelease::unrecorded(),
            ownScopeUnits: NotGiven::value(),
        );
    }

    /** This pass, whose coverage map was measured against the map its own scope keeps. */
    public function onOwnScopeCoverage(): self
    {
        return new self(
            $this->commit,
            $this->check,
            $this->ownScopeProofs,
            ownScopeCoverage: true,
            at: $this->at,
            gate: $this->gate,
            ownScopeUnits: $this->ownScopeUnits,
        );
    }

    /** This pass, its verdict having passed at this instant. */
    public function passedAt(Instant $at): self
    {
        return new self(
            $this->commit,
            $this->check,
            $this->ownScopeProofs,
            $this->ownScopeCoverage,
            $at,
            $this->gate,
            $this->ownScopeUnits,
        );
    }

    /** This pass, this release of the gate having judged it. */
    public function judgedBy(GateRelease $gate): self
    {
        return new self(
            $this->commit,
            $this->check,
            $this->ownScopeProofs,
            $this->ownScopeCoverage,
            $this->at,
            $gate,
            $this->ownScopeUnits,
        );
    }

    /**
     * This pass, these being the units whose results it took from its own
     * scope's proofs; none listed where it took none, as its record reads.
     */
    public function takingOwnScope(Paths $units): self
    {
        return new self(
            $this->commit,
            $this->check,
            $units->count(),
            $this->ownScopeCoverage,
            $this->at,
            $this->gate,
            $units->count() > 0 ? $units : NotGiven::value(),
        );
    }

    /** The units whose results it took from its own scope's proofs; none given where the record does not list them. */
    public function ownScopeUnits(): Paths|NotGiven
    {
        return $this->ownScopeProofs === 0 ? Paths::none() : $this->ownScopeUnits;
    }

    /** The release of the gate that judged it; none recorded where the record does not say. */
    public function gate(): GateRelease
    {
        return $this->gate;
    }

    /** When the verdict passed; nothing where the record does not say. */
    public function at(): Instant|NotGiven
    {
        return $this->at;
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
