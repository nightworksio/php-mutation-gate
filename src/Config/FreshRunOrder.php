<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** The order a fresh run asks Pest for (ADR-0202): `pest.freshOrder`. */
final readonly class FreshRunOrder implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /**
     * `pest.freshOrder`, `true`: a fresh run asks Pest for its likely killers first and the rest fastest first, as by
     * default.
     */
    public static function killersFirst(): self
    {
        return new self(Json::at('pest.freshOrder', value: true));
    }

    /** `pest.freshOrder`, `false`: a fresh run runs its covering tests in Pest's own order. */
    public static function pestsOwn(): self
    {
        return new self(Json::at('pest.freshOrder', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
