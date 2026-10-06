<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * What a setting can change (ADR-0007). A setting that affects results is in
 * the key a proof is stored under. One that decides how the gate runs, such
 * as what a change reaches, where proofs are read from or which of them are
 * trusted, is not in the key, but a change to it leaves no proof carried
 * across it (ADR-0005, decision 4). One that only judges or reports changes
 * neither, so changing it re-runs nothing.
 */
enum Effect: string
{
    case AffectsResults = 'affects results';
    case DecidesHowTheGateRuns = 'decides how the gate runs';
    case JudgesOrReportsOnly = 'judges or reports only';

    /** Whether a proof's key holds the setting (ADR-0007, key item 3). */
    public function isKeyed(): bool
    {
        return $this === self::AffectsResults;
    }

    /** Whether a change to the setting decides how the gate runs, as every one does but those that judge or report. */
    public function decides(): bool
    {
        return $this !== self::JudgesOrReportsOnly;
    }
}
