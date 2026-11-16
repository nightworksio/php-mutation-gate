<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** The speed-ups the limits a run lays take: where a mutant's start-up is measured; keys of `timeouts`. */
final readonly class TimeoutSpeedUps implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /**
     * `timeouts.startUpAtPlan`, `true`: a mutant's start-up is measured once, as the plan is made, for every shard of
     * its runner's platform, as by default (ADR-0165).
     */
    public static function measuringAtPlan(): self
    {
        return new self(Json::at('timeouts.startUpAtPlan', value: true));
    }

    /** `timeouts.startUpAtPlan`, `false`: each shard measures its mutants' start-up itself. */
    public static function measuringInEachShard(): self
    {
        return new self(Json::at('timeouts.startUpAtPlan', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
