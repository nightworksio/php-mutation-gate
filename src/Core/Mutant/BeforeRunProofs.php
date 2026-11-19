<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/**
 * What `outbreak.so` found of a run's mutants before any test ran (ADR-0130):
 * the proof of each it proved equivalent, and the digest of the op arrays of
 * each it compiled, which tells two mutants of one site that are one program
 * (ADR-0137), each by the mutant's key.
 *
 * @internal
 */
final readonly class BeforeRunProofs
{
    /**
     * @param array<string, Reason> $proofs
     * @param array<string, string> $programs
     */
    public function __construct(public array $proofs, public array $programs)
    {
    }
}
