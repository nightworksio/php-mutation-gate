<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** How fresh runs go faster: the order each asks Pest for (ADR-0202), and racing on idle places (ADR-0203). */
final readonly class FreshRunSpeedUps implements Setting
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
    public static function pestsOwnOrder(): self
    {
        return new self(Json::at('pest.freshOrder', value: false));
    }

    /**
     * `pest.freshRace`, `true`: where places are idle, a fresh run's covering tests also run in parts beside it, and a
     * part's kill its control vouches for settles the mutant, as by default.
     */
    public static function racing(): self
    {
        return new self(Json::at('pest.freshRace', value: true));
    }

    /** `pest.freshRace`, `false`: each fresh run runs alone. */
    public static function runningAlone(): self
    {
        return new self(Json::at('pest.freshRace', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
