<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * Whether Outbreak's core takes the coverage maps' bulk off the gate's PHP,
 * its binary, how long a run of it may take and its threads (ADR-0118), and
 * how it proves equivalence and judges a split stream's kills (ADR-0130,
 * ADR-0147): `outbreak`. Its cache, graph and daemon have builders of their
 * own: OutbreakCache, OutbreakGraph and OutbreakServe.
 */
final readonly class Outbreak implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `outbreak.enabled`, as by default: the gate runs the binary where one fits and passes its checksum. */
    public static function on(): self
    {
        return new self(Json::at('outbreak.enabled', value: true));
    }

    /** `outbreak.enabled` off: the gate does every part of the work in PHP. */
    public static function off(): self
    {
        return new self(Json::at('outbreak.enabled', value: false));
    }

    /** `outbreak.binary`: the binary to run, from the project's root where relative, in place of the shipped one. */
    public static function binary(string $path): self
    {
        return new self(Json::at('outbreak.binary', $path));
    }

    /** `outbreak.timeout`: how long one run of the binary may take, such as `90s`; 5 minutes by default. */
    public static function timeout(string $duration): self
    {
        return new self(Json::at('outbreak.timeout', $duration));
    }

    /** `outbreak.threads`: how many threads the binary writes a plan's maps on; every core by default. */
    public static function threads(int $threads): self
    {
        return new self(Json::at('outbreak.threads', $threads));
    }

    /** `outbreak.equivalence` on: `outbreak.so` proves a mutant equivalent before it runs, as it does by default. */
    public static function equivalenceOn(): self
    {
        return new self(Json::at('outbreak.equivalence', value: true));
    }

    /** `outbreak.forkStates` on: a split stream counts the forks that change no state (H5). */
    public static function forkStatesCounted(): self
    {
        return new self(Json::at('outbreak.forkStates', value: true));
    }

    /** `outbreak.forkStates` off, as by default: a split stream counts nothing of its forks' states. */
    public static function forkStatesUncounted(): self
    {
        return new self(Json::at('outbreak.forkStates', value: false));
    }

    /**
     * `outbreak.recheckTouchedKills` on, as by default: a stream's kill by a test that touched nondeterminism is judged
     * again.
     */
    public static function touchedKillsRechecked(): self
    {
        return new self(Json::at('outbreak.recheckTouchedKills', value: true));
    }

    /** `outbreak.recheckTouchedKills` off, which is loose: a stream's kill counts whatever its test touched. */
    public static function touchedKillsTrusted(): self
    {
        return new self(Json::at('outbreak.recheckTouchedKills', value: false));
    }

    /** `outbreak.equivalence` off: every mutant runs, and only survivors are proven equivalent after the run. */
    public static function equivalenceOff(): self
    {
        return new self(Json::at('outbreak.equivalence', value: false));
    }

    /** `outbreak.lines` on, as by default: a stream records its tests' lines with `outbreak.so` where it loads. */
    public static function linesRecorded(): self
    {
        return new self(Json::at('outbreak.lines', value: true));
    }

    /** `outbreak.lines` off: a stream records its tests' lines with pcov alone. */
    public static function linesByPcov(): self
    {
        return new self(Json::at('outbreak.lines', value: false));
    }

    /**
     * `outbreak.steps` on, as by default: a stream holds a child to its parent's steps where `outbreak.so` counts them.
     */
    public static function stepsCounted(): self
    {
        return new self(Json::at('outbreak.steps', value: true));
    }

    /** `outbreak.steps` off: a stream holds a child to its parent's time. */
    public static function stepsUncounted(): self
    {
        return new self(Json::at('outbreak.steps', value: false));
    }

    /** `outbreak.stepFloor`: the least steps a child may take past its parent's share; ten million by default. */
    public static function stepFloor(int $steps): self
    {
        return new self(Json::at('outbreak.stepFloor', $steps));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
