<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** The budgets of the local modes (ADR-0010): `local`. */
final readonly class Local implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `local.watchBudget` */
    public static function watchBudget(string $duration): self
    {
        return new self(Json::at('local.watchBudget', $duration));
    }

    /** `local.prePushBudget` */
    public static function prePushBudget(string $duration): self
    {
        return new self(Json::at('local.prePushBudget', $duration));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
