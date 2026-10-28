<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * `dashboard.topic` and `dashboard.repositories`: which repositories an
 * organisation's dashboard shows, by topic or by name (ADR-0024).
 */
final readonly class Dashboard implements Setting
{
    private function __construct(private Json $json)
    {
    }

    public static function topic(string $topic): self
    {
        return new self(Json::at('dashboard.topic', $topic));
    }

    public static function repositories(string ...$names): self
    {
        return new self(Json::at('dashboard.repositories', Json::items(...$names)));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
