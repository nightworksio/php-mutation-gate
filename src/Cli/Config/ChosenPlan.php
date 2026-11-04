<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use NightWorksIO\MutationGate\Core\Ci\CiEnvironment;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Extension\Extensions;

/**
 * The CI plan a run uses (ADR-0006): the one the config names, with the options its `ci.*` settings give laid
 * under its own, or the one a registered plan detects the job runs in, with those options; the JSON plan where
 * none does.
 *
 * @internal
 */
final readonly class ChosenPlan
{
    public static function of(Settings $settings, Extensions $registry, Variables $environment): Choice
    {
        $named = $settings->ci()->plan();
        $found = $registry->detectedCiPlan(CiEnvironment::of($environment)->shows(...));
        $detected = $found instanceof Name ? $found : BuiltinCiPlan::Json->named();

        return $named instanceof Choice
            ? $named
            : Choice::of($detected->value(), $settings->ci()->planOptions($detected));
    }
}
