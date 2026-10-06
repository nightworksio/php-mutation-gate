<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Format\Json;

/** How timeouts are judged (ADR-0008): `timeouts`. */
final readonly class Timeouts implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** A timeout is a kill when its limit allowed the covering tests their multiple of their own time (ADR-0008). */
    public static function confirmed(): self
    {
        return new self(Json::at('timeouts.mode', TimeoutMode::Confirm->value));
    }

    /** A timeout is always too slow to judge. */
    public static function unjudged(): self
    {
        return new self(Json::at('timeouts.mode', TimeoutMode::Unjudged->value));
    }

    /** `timeouts.seconds` */
    public static function seconds(int $seconds): self
    {
        return new self(Json::at('timeouts.seconds', $seconds));
    }

    /** `timeouts.most` */
    public static function most(int $seconds): self
    {
        return new self(Json::at('timeouts.most', $seconds));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
