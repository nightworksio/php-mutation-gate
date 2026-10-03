<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\NativeMarkers;
use NightWorksIO\MutationGate\Core\Format\Json;

/** The rules for ignores (ADR-0008): `ignores.maxDays` and `ignores.native`. */
final readonly class Ignores implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** Every ignore must expire within this many days of a run. */
    public static function within(int $days): self
    {
        return new self(Json::at('ignores.maxDays', $days));
    }

    /** The runners' own ignore markers are allowed, while a project moves them into the config. */
    public static function allowingNativeMarkers(): self
    {
        return new self(Json::at('ignores.native', NativeMarkers::Allow->value));
    }

    /** The runners' own ignore markers stop the run. */
    public static function refusingNativeMarkers(): self
    {
        return new self(Json::at('ignores.native', NativeMarkers::Refuse->value));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
