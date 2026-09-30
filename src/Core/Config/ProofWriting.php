<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** Whether the verdict writes the run's proofs to the store (ADR-0007). */
enum ProofWriting: string
{
    /** To the run's own scope. */
    case Auto = 'auto';

    /** Never: the store is read-only. */
    case Never = 'never';
}
