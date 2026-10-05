<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/**
 * An object a store keeps per scope beside the ledger, under the ledger's
 * read and write rules (ADR-0023, decision 2), by its name in the scope's
 * directory. CompanionRead says how far a store reads each.
 */
enum Companion: string
{
    /** The coverage map, with each test file's entry key, that a later run measures only what moved against. */
    case Coverage = 'coverage.json.gz';

    /** How a run names it. */
    public function named(): string
    {
        return match ($this) {
            self::Coverage => 'coverage map',
        };
    }
}
