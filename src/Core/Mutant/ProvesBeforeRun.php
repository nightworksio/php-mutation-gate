<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\File\Contents;

/**
 * What proves a run's mutants before any test runs (ADR-0130): each mutant
 * whose op arrays and declarations are its original's is equivalent, and
 * the digest of each mutant's op arrays tells two mutants of one site that
 * are one program (ADR-0137).
 *
 * @internal
 */
interface ProvesBeforeRun extends PrintsDeclarations
{
    /**
     * The proof of each of these mutants proven equivalent, and the digest of
     * each mutant's op arrays, by its key.
     *
     * @param list<array{string, Contents, Contents}> $pairs each mutant's key, its original and the mutant
     */
    public function proven(array $pairs): BeforeRunProofs;
}
