<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\HeldCoverageMode;
use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * How held units are read (ADR-0005): which code is hot enough that nothing
 * holding it is warned of, and where a held unit's lines are read from:
 * `holds`.
 */
final readonly class HeldUnits implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `holds.hotPath`: the share of the suite past which code nothing holds is warned about. */
    public static function hotPath(int|float $share): self
    {
        return new self(Json::at('holds.hotPath', $share));
    }

    /** `holds.fewestTests`: the fewest tests a suite holds for a file to read as hot, 20 by default. */
    public static function fewestTests(int $tests): self
    {
        return new self(Json::at('holds.fewestTests', $tests));
    }

    /** `holds.coverage`, `measure`: a held unit's holding tests run alone under coverage first, as by default. */
    public static function measuringCoverage(): self
    {
        return new self(Json::at('holds.coverage', HeldCoverageMode::Measure->value));
    }

    /** `holds.coverage`, `map`: their lines are read from the handed map, which loosens under `uncovered: exclude`. */
    public static function coverageFromTheMap(): self
    {
        return new self(Json::at('holds.coverage', HeldCoverageMode::Map->value));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
