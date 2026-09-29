<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * What a setting can change (ADR-0007). A setting that affects results is in
 * the key a proof is stored under; one that only judges or reports is not, so
 * changing it re-runs nothing.
 */
enum Effect: string
{
    case AffectsResults = 'affects results';
    case JudgesOrReportsOnly = 'judges or reports only';
}
