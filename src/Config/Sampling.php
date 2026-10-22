<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * How a sampled run, `--sample`, sizes and draws its sample (ADR-0020):
 * `sampling`.
 */
final readonly class Sampling implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `sampling.confidence`: 90, 95 (the default) or 99, how sure each tree's interval is to hold its score. */
    public static function confidence(int $percent): self
    {
        return new self(Json::at('sampling.confidence', $percent));
    }

    /** `sampling.margin`: the half-width in points each tree's interval is sized for, above 0 and at most 10. */
    public static function margin(int|float $points): self
    {
        return new self(Json::at('sampling.margin', $points));
    }

    /** `sampling.minUnits`: the fewest units a tree's sampled stratum runs, 30 by default. */
    public static function minUnits(int $units): self
    {
        return new self(Json::at('sampling.minUnits', $units));
    }

    /** `sampling.seed`: what the sample is drawn from, in place of the run's base or commit. */
    public static function seed(string $seed): self
    {
        return new self(Json::at('sampling.seed', $seed));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
