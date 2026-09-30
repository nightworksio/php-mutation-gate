<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Json;

/** The budgets of the local modes (ADR-0010): `local`. */
final readonly class Local implements Setting
{
    private function __construct(private string $json)
    {
    }

    /** `local.watchBudget` */
    public static function watchBudget(string $duration): self
    {
        return new self(Json::encode(['local' => ['watchBudget' => $duration]]));
    }

    /** `local.prePushBudget` */
    public static function prePushBudget(string $duration): self
    {
        return new self(Json::encode(['local' => ['prePushBudget' => $duration]]));
    }

    public function written(): string
    {
        return $this->json;
    }
}
