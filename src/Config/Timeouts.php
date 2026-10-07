<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use function array_values;

use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

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

    /**
     * `timeouts.tighter`: the silence limit of a mutant of these mutators,
     * each by its short name, the last part of the name a runner gives it,
     * is kept above this floor in seconds instead of `timeouts.seconds`.
     */
    public static function tighter(int $floor, string ...$mutators): self
    {
        $tighter = Json::object(
            Member::of('mutators', Json::items(...array_values($mutators))),
            Member::of('floor', $floor),
        );

        return new self(Json::at('timeouts.tighter', $tighter));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
