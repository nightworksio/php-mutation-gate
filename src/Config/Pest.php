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
        return new self(Json::at('pest.patch', value: true));
    }

    /** The optional Pest patches are not applied. */
    public static function unpatched(): self
    {
        return new self(Json::at('pest.patch', value: false));
    }

    /** `pest.bootTail`: the verification tail's workers boot the project before they fork (ADR-0075). */
    public static function bootingTail(): self
    {
        return new self(Json::at('pest.bootTail', value: true));
    }

    /** `pest.bootTail`: the verification tail's workers boot nothing of the project, as mutants' do. */
    public static function tailUnbooted(): self
    {
        return new self(Json::at('pest.bootTail', value: false));
    }

    /** `pest.canary`: the group of tests that shows the patches work. */
    public static function canary(string $group): self
    {
        return new self(Json::at('pest.canary', $group));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
