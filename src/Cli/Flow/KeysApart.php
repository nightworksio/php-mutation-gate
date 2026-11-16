<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;

/**
 * What a plan off the default branch says of the default branch's proofs it could not take: of the units it runs,
 * those the default branch proved of their source as it is now under another key, which their tests or a setting
 * that affects results moved (ADR-0112). Where `suggest.enabled` is off, nothing.
 *
 * @internal
 */
final readonly class KeysApart
{
    private const string SAID = <<<'SAID'
        The default branch proved %d of the %s this run mutates for the same code under another key, so they run
        again: their tests, or a setting that affects results, differ. Where a context block sets one for pull
        requests alone, doctor names it.
        SAID;

    /**
     * These reasons, and one saying how many of the units a run mutates the default branch proved of their source
     * now under another key, where there are any.
     */
    public static function said(
        Reasons $reasons,
        Units $toRun,
        Ledger $defaultBranch,
        RunProfile $kind,
        Digests|Undigested $now,
        bool $offTheDefaultBranch,
    ): Reasons {
        if (! $offTheDefaultBranch) {
            return $reasons;
        }

        $proved = $defaultBranch->proofs()->standingFor($kind)->ofSourcesIn($now)->newest();
        $apart = 0;

        foreach ($toRun as $unit) {
            $apart += $proved->of($unit->path()) instanceof Proof ? 1 : 0;
        }

        return $apart > 0
            ? $reasons->with(Reason::that(sprintf(self::SAID, $apart, $toRun->count() === 1 ? 'unit' : 'units')))
            : $reasons;
    }
}
