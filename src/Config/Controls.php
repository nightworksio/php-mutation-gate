<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** Which controls a kill or an out-of-memory mutant runs before it counts (ADR-0014, ADR-0142): `controls`. */
final readonly class Controls implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `controls.kills`, `true`: every kill runs its control on the unmutated code, as by default. */
    public static function killsControlled(): self
    {
        return new self(Json::at('controls.kills', value: true));
    }

    /** `controls.kills`, `false`: a kill counts without its control, which loosens (ADR-0142). */
    public static function killsUncontrolled(): self
    {
        return new self(Json::at('controls.kills', value: false));
    }

    /** `controls.memory`, `true`: an out-of-memory mutant's tests run unmutated under the same cap, as by default. */
    public static function memoryControlled(): self
    {
        return new self(Json::at('controls.memory', value: true));
    }

    /** `controls.memory`, `false`: an out-of-memory mutant counts with no control. */
    public static function memoryUncontrolled(): self
    {
        return new self(Json::at('controls.memory', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
