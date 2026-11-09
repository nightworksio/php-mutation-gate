<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `holds.coverage`: how a shard learns which lines a held unit's holding tests cover (ADR-0005, ADR-0142). */
enum HeldCoverageMode: string
{
    /** The holding tests run alone under coverage, and must pass. */
    case Measure = 'measure';

    /** Their lines are read from the map the plan handed the shard; nothing checks that they pass alone. */
    case Map = 'map';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Measure => 'Reach::measuringHeldCoverage()',
            self::Map => 'Reach::heldCoverageFromTheMap()',
        };
    }
}
