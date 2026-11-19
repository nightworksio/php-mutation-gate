<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * The speed-ups a Pest run takes, each with its key: how a shard scans and
 * remembers, where the map it opens on is readied, whether its mutants are
 * made side by side, the order runs side by
 * side start in, whether controls overlap
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

    /**
     * `pest.readyAtPlan`, `true`: the map a patched shard opens on is readied once, as the plan is made, for every
     * shard, as by default (ADR-0163).
     */
    public static function readyingAtPlan(): self
    {
        return new self(Json::at('pest.readyAtPlan', value: true));
    }

    /**
     * `pest.makeSideBySide`, `true`: a patched run makes every file's mutants side by side, a share for each core,
     * before it takes any, as by default (ADR-0166).
     */
    public static function makingSideBySide(): self
    {
        return new self(Json::at('pest.makeSideBySide', value: true));
    }

    /** `pest.makeSideBySide`, `false`: a patched run makes one file's mutants after another's. */
    public static function makingOneAfterAnother(): self
    {
        return new self(Json::at('pest.makeSideBySide', value: false));
    }

    /** `pest.readyAtPlan`, `false`: each shard readies the map it opens on itself. */
    public static function readyingInEachShard(): self
    {
        return new self(Json::at('pest.readyAtPlan', value: false));
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
     * `pest.pcovOff`, `true`, as by default: every process a mutation run starts has pcov off, unless its streams
     * record lines with it, or it runs a test that reads the coverage driver.
     */
    public static function pcovOff(): self
    {
        return new self(Json::at('pest.pcovOff', value: true));
    }

    /** `pest.pcovOff`, `false`: every process of a mutation run has pcov as PHP is configured. */
    public static function pcovOn(): self
    {
        return new self(Json::at('pest.pcovOff', value: false));
    }

    /**
     * `pest.pcovKept`: the globs of the test files and source files whose tests keep pcov as PHP is configured where
     * `pest.pcovOff` turns it off, beside the tests the gate finds reading the coverage driver; none by default.
     */
    public static function pcovKept(string ...$globs): self
    {
        return new self(Json::at('pest.pcovKept', Json::items(...$globs)));
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
