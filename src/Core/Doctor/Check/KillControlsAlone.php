<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Config\KillControl;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

/**
 * `flaky.killControls: alone` (ADR-0143): a kill's control runs its killers
 * alone, so a test that fails only after the tests before it counts as a kill.
 *
 * @internal
 */
final readonly class KillControlsAlone
{
    private const string FOUND
        = 'flaky.killControls is alone, so a kill is controlled without the order it happened in.';

    private const string WHY
        = 'A test that fails only after the tests before it then counts as a kill, and no survivor shows it.';

    private const string FIX = 'Remove flaky.killControls, or set it to order, to replay each kill in its order.';

    public static function in(Observations $observed): Findings
    {
        $settings = $observed->config()->settings();

        return $settings instanceof Settings && $settings->triage()->flaky()->killControls() === KillControl::Alone
            ? Findings::of(Finding::of(Slug::KillControlsAlone, Severity::Advice, self::FOUND, self::WHY, self::FIX))
            : Findings::none();
    }
}
