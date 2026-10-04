<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

/**
 * How much of the kill matrix a run knows: the first test that killed each
 * mutant, or every test that fails with it, from `run --kill-matrix=full`
 * (ADR-0014, decisions 7 and 9).
 */
enum MatrixKind: string
{
    case FirstKiller = 'first-killer';
    case Full = 'full';

    /**
     * Whether a matrix of this kind holds what a run of that one asks for:
     * every kind holds first killers, and only a full one every killer.
     */
    public function holds(self $asked): bool
    {
        return $this === self::Full || $asked === self::FirstKiller;
    }
}
