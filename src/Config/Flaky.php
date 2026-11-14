<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\KillControl;
use NightWorksIO\MutationGate\Core\Format\Json;

/** How flaky tests are caught (ADR-0008, ADR-0108): `flaky`. */
final readonly class Flaky implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** Each survivor is run once more, alone, before it counts. */
    public static function confirmingSurvivors(): self
    {
        return new self(Json::at('flaky.confirmSurvivors', value: true));
    }

    /** A survivor counts as it first ran. */
    public static function notConfirmingSurvivors(): self
    {
        return new self(Json::at('flaky.confirmSurvivors', value: false));
    }

    /** A kill's control runs its run again in its order, so a test that fails only after others kills nothing. */
    public static function controllingKillsInOrder(): self
    {
        return new self(Json::at('flaky.killControls', KillControl::Order->value));
    }

    /** A kill's control runs its killers alone, which is quicker and gives up that proof. */
    public static function controllingKillsAlone(): self
    {
        return new self(Json::at('flaky.killControls', KillControl::Alone->value));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
