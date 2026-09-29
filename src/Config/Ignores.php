<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Json;

/** The rules for ignores (ADR-0008): `ignores.maxDays` and `ignores.native`. */
final readonly class Ignores implements Setting
{
    private function __construct(private string $json)
    {
    }

    /** Every ignore must expire within this many days of a run. */
    public static function within(int $days): self
    {
        return new self(Json::encode(['ignores' => ['maxDays' => $days]]));
    }

    /** The runners' own ignore markers are allowed, while a project moves them into the config. */
    public static function allowingNativeMarkers(): self
    {
        return new self(Json::encode(['ignores' => ['native' => 'allow']]));
    }

    /** The runners' own ignore markers stop the run. */
    public static function refusingNativeMarkers(): self
    {
        return new self(Json::encode(['ignores' => ['native' => 'refuse']]));
    }

    public function written(): string
    {
        return $this->json;
    }
}
