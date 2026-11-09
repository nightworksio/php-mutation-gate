<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function implode;

use NightWorksIO\MutationGate\Core\Config\KeyedContexts;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * The config's `pr` and `main` blocks leave a setting that affects results
 * different, so a pull request can take none of the default branch's proofs
 * of what it reaches (ADR-0071, ADR-0112). Unless `suggest.enabled` is off.
 *
 * @internal
 */
final readonly class KeyedContextBlocks
{
    private const string FOUND = 'A pull request and the default branch resolve %s differently.';

    private const string WHY = 'Each is in a proof\'s key, so a pull request takes none of the default branch\'s'
        . ' proofs of the units it reaches, and runs them.';

    private const string FIX = 'Set them alike for pr and main, or outside both blocks.';

    public static function in(Observations $observed): Findings
    {
        $settings = $observed->settings();
        $written = $observed->written();
        $keys = $written instanceof Layer
            && $settings instanceof Settings
            && $settings->effective()->reports()->suggest()->enabled()
            ? KeyedContexts::of($written)->keys()
            : [];

        return $keys === []
            ? Findings::none()
            : Findings::of(Finding::of(
                Slug::KeyedContextBlocks,
                Severity::Slow,
                sprintf(self::FOUND, implode(Fit::COMMA, $keys)),
                self::WHY,
                self::FIX,
            ));
    }
}
