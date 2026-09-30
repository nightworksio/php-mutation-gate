<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;

/**
 * A project's Infection config, as far as adopting the gate carries it over
 * (ADR-0016): whether it sets a floor of its own, `minMsi` or
 * `minCoveredMsi`, and whether it ignores mutants by Infection's own rules.
 */
final readonly class Importable
{
    /** The keys that set Infection's own floors. */
    private const array FLOORS = ['minMsi', 'minCoveredMsi'];

    /** The config in a file's text, or why it is not one Infection could read. */
    public static function in(string $file, string $text): InfectionConfig|CannotJudge
    {
        $settings = RelaxedJson::decode($file, $text);

        if ($settings instanceof CannotJudge) {
            return $settings;
        }

        $floored = false;

        foreach (self::FLOORS as $floor) {
            $floored = $floored || $settings->field($floor)->isPresent();
        }

        return InfectionConfig::in(
            $file,
            minMsi: $floored,
            ignores: MutatorSettings::of($settings->field('mutators'))->patterns() !== [],
        );
    }
}
