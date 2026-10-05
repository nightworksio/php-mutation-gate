<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** How the gate measures the coverage map (ADR-0023): `coverage`. */
final readonly class Coverage implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** A run measures again only the test files whose coverage could have moved, keeping the rest. */
    public static function incremental(): self
    {
        return new self(Json::at('coverage.incremental', value: true));
    }

    /** A run measures every test. */
    public static function full(): self
    {
        return new self(Json::at('coverage.incremental', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
