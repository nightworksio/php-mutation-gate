<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** How a run plans its work: `plan`. */
final readonly class Plan implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `plan.chunkMinutes`: how much expected work a chunk of a shard that can stop once it cannot pass holds; 15. */
    public static function chunkMinutes(int $minutes): self
    {
        return new self(Json::at('plan.chunkMinutes', $minutes));
    }

    /** `plan.provingRange`: how many commits since a base a change source asks to have proved; 20. */
    public static function provingRange(int $commits): self
    {
        return new self(Json::at('plan.provingRange', $commits));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
