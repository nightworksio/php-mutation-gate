<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** How a run learns what its work costs: `timing`. */
final readonly class Timing implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `timing.newest`: the weight, above 0 and at most 1, a unit's newest measurement takes in its timing; 0.9. */
    public static function newest(float $weight): self
    {
        return new self(Json::at('timing.newest', $weight));
    }

    /** `timing.startUpSamples`: how many runs of no test a plan times to read the start-up; 3. */
    public static function startUpSamples(int $runs): self
    {
        return new self(Json::at('timing.startUpSamples', $runs));
    }

    /**
     * `timing.planSteps`: whether the plan prints how long each of its steps took and records them beside the plan;
     * true.
     */
    public static function planSteps(bool $timed): self
    {
        return new self(Json::at('timing.planSteps', $timed));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
