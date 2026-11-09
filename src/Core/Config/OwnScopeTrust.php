<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * `ci.mergedOwnScopeProofs`: what a merged pull request's pass that took results from its own scope's proofs is
 * trusted for (ADR-0139).
 */
enum OwnScopeTrust: string
{
    /** Its own results, the units it took from its own scope reached and run again. */
    case Reach = 'reach';

    /** Nothing: such a pass spares no commit. */
    case Refuse = 'refuse';

    /** The whole pass, own-scope results included. */
    case Trust = 'trust';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Reach => 'Ci::reachingMergedOwnScopeProofs()',
            self::Refuse => 'Ci::refusingMergedOwnScopeProofs()',
            self::Trust => 'Ci::trustingMergedOwnScopeProofs()',
        };
    }

    /** Whether it loosens what the default branch judges (ADR-0142). */
    public function isLoose(): bool
    {
        return $this === self::Trust;
    }
}
