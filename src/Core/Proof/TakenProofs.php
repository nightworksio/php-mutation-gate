<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/**
 * Which proofs of the ledgers it reads a run takes, so that a unit one of
 * them proves or carries runs no mutant.
 */
enum TakenProofs
{
    /** Every proof whose key still matches, or whose source a unit still has. */
    case Every;

    /** The default branch's alone, none the run's own scope left: `proofs.ownScope` off (ADR-0081). */
    case DefaultBranchOnly;

    /** None: every unit the run considers runs, as the backstop of a key that reads less than everything (ADR-0084). */
    case None;
}
