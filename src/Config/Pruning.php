<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * Whether a mutator that let no mutant through lately is pruned on unchanged
 * code (ADR-0025): `pruning`.
 */
final readonly class Pruning implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `pruning.enabled`: true, the default. */
    public static function on(): self
    {
        return new self(Json::at('pruning.enabled', value: true));
    }

    /** `pruning.enabled`: false, so every mutator runs on every unit. */
    public static function off(): self
    {
        return new self(Json::at('pruning.enabled', value: false));
    }

    /** `pruning.window`: how many of a mutator's newest judged mutants must all be killed before it is pruned. */
    public static function window(int $mutants): self
    {
        return new self(Json::at('pruning.window', $mutants));
    }

    /** `pruning.audit`: how old a unit's newest full result may be before every mutator runs on it again. */
    public static function auditEvery(string $duration): self
    {
        return new self(Json::at('pruning.audit', $duration));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
