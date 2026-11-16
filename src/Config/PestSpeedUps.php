<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * The speed-ups a Pest run takes, each with its key: how a shard scans and
 * remembers, the order runs side by side start in, whether controls overlap
 * survivors' runs again, and the coverage drivers a mutation run turns off:
 * keys of `pest`.
 */
final readonly class PestSpeedUps implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `pest.shardScan`, `true`: a shard scans the codebase for a value's readers once, not once a chunk. */
    public static function scanningOnceAShard(): self
    {
        return new self(Json::at('pest.shardScan', value: true));
    }

    /** `pest.shardScan`, `false`: each chunk scans the codebase afresh. */
    public static function scanningEachChunk(): self
    {
        return new self(Json::at('pest.shardScan', value: false));
    }

    /** `pest.longestFirst`, `true`: commands run side by side start the longest-limited first, as by default. */
    public static function longestFirst(): self
    {
        return new self(Json::at('pest.longestFirst', value: true));
    }

    /** `pest.longestFirst`, `false`: they start in the order the run gives them. */
    public static function inTheirOrder(): self
    {
        return new self(Json::at('pest.longestFirst', value: false));
    }

    /** `pest.remember`, `true`: a shard keeps what it read, listed and ran unmutated for its later runs. */
    public static function remembering(): self
    {
        return new self(Json::at('pest.remember', value: true));
    }

    /** `pest.remember`, `false`: each run of a shard reads, lists and runs afresh. */
    public static function forgetting(): self
    {
        return new self(Json::at('pest.remember', value: false));
    }

    /** `pest.overlapSteps`, `true`: an invocation's controls run beside its survivors' runs again, as by default. */
    public static function overlappingSteps(): self
    {
        return new self(Json::at('pest.overlapSteps', value: true));
    }

    /** `pest.overlapSteps`, `false`: the survivors' runs again end before the controls start. */
    public static function stepByStep(): self
    {
        return new self(Json::at('pest.overlapSteps', value: false));
    }

    /**
     * `pest.pcovOff`, `true`: a mutation run starts with pcov off unless its streams record lines with it, as by
     * default.
     */
    public static function pcovOff(): self
    {
        return new self(Json::at('pest.pcovOff', value: true));
    }

    /** `pest.pcovOff`, `false`: a mutation run starts with pcov as PHP is configured. */
    public static function pcovOn(): self
    {
        return new self(Json::at('pest.pcovOff', value: false));
    }

    /** `pest.xdebugOff`, `true`: a mutation run starts with Xdebug's mode off, as by default. */
    public static function xdebugOff(): self
    {
        return new self(Json::at('pest.xdebugOff', value: true));
    }

    /** `pest.xdebugOff`, `false`: a mutation run starts with Xdebug as PHP is configured. */
    public static function xdebugAsConfigured(): self
    {
        return new self(Json::at('pest.xdebugOff', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
