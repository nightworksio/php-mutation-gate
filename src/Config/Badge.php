<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** `badge.colors`: the lowest score of each shields.io colour (ADR-0009). */
final readonly class Badge implements Setting
{
    private function __construct(private Json $json)
    {
    }

    public static function colour(string $colour, int|float $lowest): self
    {
        return new self(Json::decoded(['badge' => ['colors' => [$colour => $lowest]]]));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
