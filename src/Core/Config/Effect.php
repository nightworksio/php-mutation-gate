<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * What a setting can change (ADR-0007). A setting that affects results is in
 * the key a proof is stored under. One that decides how the gate runs, such
 * as what a change reaches, where proofs are read from or which of them are
 * trusted, is not in the key, but a change to it leaves no proof carried
 * across it (ADR-0005). One that only judges or reports changes
 * neither, so changing it re-runs nothing. One that affects the results of
 * the units a `paths` entry matches is in those units' keys alone, and a
 * change to it reaches only them (ADR-0074).
 *
 * @internal
 */
enum Effect: string
{
    case AffectsResults = 'affects results';
    case DecidesHowTheGateRuns = 'decides how the gate runs';
    case JudgesOrReportsOnly = 'judges or reports only';
    case AffectsMatchingUnits = 'affects the units it matches';

    /** Whether a proof's key holds the setting (ADR-0007, key item 3). */
    public function isKeyed(): bool
    {
        return $this === self::AffectsResults;
    }

    /**
     * Whether a change to the setting decides how the gate runs, as every one does but those that judge or report
     * and those that reach only the units they match.
     */
    public function decides(): bool
    {
        return $this === self::AffectsResults || $this === self::DecidesHowTheGateRuns;
    }
}
