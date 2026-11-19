<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** How a schema's switches are called (ADR-0204): `pest.switchGuard`. */
final readonly class SchemaSwitches implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `pest.switchGuard`, `true`: a schema's switch is guarded, so a decided site makes no call, as by default. */
    public static function guarded(): self
    {
        return new self(Json::at('pest.switchGuard', value: true));
    }

    /** `pest.switchGuard`, `false`: every site of a schema calls its switch at every execution. */
    public static function unguarded(): self
    {
        return new self(Json::at('pest.switchGuard', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
