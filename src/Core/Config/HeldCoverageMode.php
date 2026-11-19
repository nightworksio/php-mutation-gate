<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `holds.coverage`: how a run learns which lines a held unit's holding tests cover (ADR-0005, 0142, 0161). */
enum HeldCoverageMode: string
{
    /** The holding tests run alone under coverage, and must pass, once for the whole run, as the plan is made. */
    case Measure = 'measure';

    /** The same run alone, in every shard that holds the unit. */
    case MeasureInEachShard = 'measure-in-each-shard';

    /** Their lines are read from the map the plan handed the shard; nothing checks that they pass alone. */
    case Map = 'map';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Measure => 'HeldUnits::measuringCoverage()',
            self::MeasureInEachShard => 'HeldUnits::measuringCoverageInEachShard()',
            self::Map => 'HeldUnits::coverageFromTheMap()',
        };
    }
}
