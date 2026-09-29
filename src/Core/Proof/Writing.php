<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/** Whether a run writes its ledger: `proofs.write`. */
enum Writing: string
{
    /** The verdict writes the run's own scope. */
    case Auto = 'auto';

    /** The store is read-only. */
    case Never = 'never';
}
