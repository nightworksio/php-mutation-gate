<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;

use function sprintf;

/**
 * What a plan says of the units its own scope's earlier runs stand for: a
 * new push to a pull request mutates again only the units whose key moved
 * (ADR-0081).
 *
 * @internal
 */
final readonly class OwnScopeStanding
{
    private const string SAID = <<<'SAID'
        This scope's earlier runs stand for %d %s whose code and tests have not moved since,
        so they are not mutated again. Set proofs.ownScope to false to run them again.
        SAID;

    /** These reasons, and one saying how many units the scope's earlier runs stand for, where they stand for any. */
    public static function said(Reasons $reasons, int $standing): Reasons
    {
        return $standing > 0
            ? $reasons->with(Reason::that(sprintf(self::SAID, $standing, $standing === 1 ? 'unit' : 'units')))
            : $reasons;
    }
}
