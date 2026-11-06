<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Why the config cannot say a change `config:suggest` proposes (ADR-0076):
 * the settings it makes are refused, or the adapters they choose cannot be
 * built, as a run would refuse them.
 *
 * @internal
 */
final readonly class Unsayable
{
    /** Why a composed run refuses this change laid over its settings; nothing where it takes it. */
    public static function why(Composed $composed, Layer $change): string|NotGiven
    {
        $settings = Settings::settled($composed->settings->effective()->over($change), $composed->setup->clock->now());
        $adapters = $settings instanceof Settings
            ? $composed->ruling->adapters($composed->adapters, $settings)
            : $settings;

        return match (true) {
            $adapters instanceof Invalid => $adapters->said(),
            $adapters instanceof CannotJudge => $adapters->why(),
            default => NotGiven::value(),
        };
    }
}
