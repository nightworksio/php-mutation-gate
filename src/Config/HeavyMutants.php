<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** How a heavy mutant's parts run (ADR-0114): `timeouts.heavyFill` and `timeouts.heavyParts`. */
final readonly class HeavyMutants implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `timeouts.heavyFill`: a round of heavy mutants' parts fills its pool with parts ahead, as by default. */
    public static function filling(): self
    {
        return new self(Json::at('timeouts.heavyFill', value: true));
    }

    /** `timeouts.heavyFill` off: a heavy mutant runs one part at a time. */
    public static function notFilling(): self
    {
        return new self(Json::at('timeouts.heavyFill', value: false));
    }

    /**
     * `timeouts.heavyParts`: a heavy mutant no part has settled by this many
     * parts is left unjudged, which fails the verdict.
     */
    public static function partsAtMost(int $parts): self
    {
        return new self(Json::at('timeouts.heavyParts', $parts));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
