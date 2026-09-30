<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** The Pest runner's own settings (ADR-0004): `pest`. */
final readonly class Pest implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** The optional Pest patches are applied. */
    public static function patched(): self
    {
        return new self(Json::decoded(['pest' => ['patch' => true]]));
    }

    /** The optional Pest patches are not applied. */
    public static function unpatched(): self
    {
        return new self(Json::decoded(['pest' => ['patch' => false]]));
    }

    /** `pest.canary`: the group of tests that shows the patches work. */
    public static function canary(string $group): self
    {
        return new self(Json::decoded(['pest' => ['canary' => $group]]));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
