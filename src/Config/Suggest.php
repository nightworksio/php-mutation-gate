<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** Whether the gate suggests changes (ADR-0104), and when `config:suggest` proposes one (ADR-0076): `suggest`. */
final readonly class Suggest implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `suggest.enabled`, `true`: the gate suggests changes, as it does by default. */
    public static function on(): self
    {
        return new self(Json::at('suggest.enabled', value: true));
    }

    /** `suggest.enabled`, `false`: the gate suggests nothing, and `config:suggest` says suggestions are off. */
    public static function off(): self
    {
        return new self(Json::at('suggest.enabled', value: false));
    }

    /** `suggest.idle.judged`: how many of a mutator's mutants must be judged before it can be idle; 500. */
    public static function idleJudged(int $mutants): self
    {
        return new self(Json::at('suggest.idle.judged', $mutants));
    }

    /** `suggest.idle.survivors`: the most survivors an idle mutator has; 0. */
    public static function idleSurvivors(int $survivors): self
    {
        return new self(Json::at('suggest.idle.survivors', $survivors));
    }

    /** `suggest.idle.share`: the least share of every mutator's time, in percent, an idle mutator takes; 5. */
    public static function idleShare(int|float $percent): self
    {
        return new self(Json::at('suggest.idle.share', $percent));
    }

    /** `suggest.ignored.share`: the least share of a noisy mutator's mutants, in percent, ignored or equivalent; 80. */
    public static function ignoredShare(int|float $percent): self
    {
        return new self(Json::at('suggest.ignored.share', $percent));
    }

    /** `suggest.ignored.mutants`: how many mutants a mutator must make before it can be noisy; 50. */
    public static function ignoredMutants(int $mutants): self
    {
        return new self(Json::at('suggest.ignored.mutants', $mutants));
    }

    /** `suggest.timeouts.cluster`: how many mutants of one directory must run out of time before it is slow; 3. */
    public static function timeoutCluster(int $mutants): self
    {
        return new self(Json::at('suggest.timeouts.cluster', $mutants));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
