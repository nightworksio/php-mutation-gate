<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * Where Outbreak's static graph acts on a run's reach (ADR-0132):
 * `outbreak.graph`.
 */
final readonly class OutbreakGraph implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `outbreak.graph`, `local`, as by default: the static graph adds tests to `watch`'s quick rounds alone. */
    public static function inQuickRounds(): self
    {
        return new self(Json::at('outbreak.graph', 'local'));
    }

    /**
     * `outbreak.graph`, `narrow`: a change the coverage map cannot place reaches what the static graph joins to it
     * in place of its whole package. A loose value.
     */
    public static function narrowing(): self
    {
        return new self(Json::at('outbreak.graph', 'narrow'));
    }

    /** `outbreak.graph`, `widen`: a changed source file also reaches the units its joined tests run. */
    public static function widening(): self
    {
        return new self(Json::at('outbreak.graph', 'widen'));
    }

    /** `outbreak.graph`, `off`: the static graph acts nowhere. */
    public static function off(): self
    {
        return new self(Json::at('outbreak.graph', 'off'));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
