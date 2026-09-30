<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** How flaky tests are caught (ADR-0008): `flaky.confirmSurvivors`. */
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

    public function written(): Json
    {
        return $this->json;
    }
}
