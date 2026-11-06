<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** `badge.colors`, the lowest score of each shields.io colour, and `badge.logo`, the badges' logo (ADR-0009). */
final readonly class Badge implements Setting
{
    private function __construct(private Json $json)
    {
    }

    public static function colour(string $colour, int|float $lowest): self
    {
        return new self(Json::at('badge.colors', Json::object(Member::of($colour, $lowest))));
    }

    /** `true` for the gate's helix, `false` for none, or the path of an SVG file. */
    public static function logo(bool|string $logo): self
    {
        return new self(Json::at('badge.logo', $logo));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
