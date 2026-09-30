<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** `budget`: how long a run may take, riskiest code first (ADR-0008). */
final readonly class Budget implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** A duration, `90s`, `15m` or `1h30m`. */
    public static function of(string $duration): self
    {
        return new self(Json::decoded(['budget' => $duration]));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
