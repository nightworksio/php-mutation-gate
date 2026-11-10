<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * Whether Outbreak's core takes the coverage maps' bulk off the gate's PHP,
 * its binary, how long a run of it may take and its threads (ADR-0118), and
 * where `outbreak cache` keeps proofs by their keys (ADR-0131): `outbreak`.
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

    /** `outbreak.equivalence` off: every mutant runs, and only survivors are proven equivalent after the run. */
    public static function equivalenceOff(): self
    {
        return new self(Json::at('outbreak.equivalence', value: false));
    }

    /** `outbreak.cache.store`, `auto`, as by default: a registry where one is named, the Actions cache under the action. */
    public static function cacheStoredAuto(): self
    {
        return new self(Json::at('outbreak.cache.store', 'auto'));
    }

    /** `outbreak.cache.store`, `none`: a run proves from its ledgers alone. */
    public static function cacheStoredNowhere(): self
    {
        return new self(Json::at('outbreak.cache.store', 'none'));
    }

    /** `outbreak.cache.store`, `actions`: each pack an entry of the Actions cache. */
    public static function cacheStoredInActions(): self
    {
        return new self(Json::at('outbreak.cache.store', 'actions'));
    }

    /** `outbreak.cache.store`, `oci`: each pack an artifact in the repository `outbreak.cache.registry` names. */
    public static function cacheStoredInRegistry(): self
    {
        return new self(Json::at('outbreak.cache.store', 'oci'));
    }

    /** `outbreak.cache.registry`: the OCI repository the packs live in, such as `ghcr.io/owner/proofs`. */
    public static function cacheRegistry(string $repository): self
    {
        return new self(Json::at('outbreak.cache.registry', $repository));
    }

    /** `outbreak.cache.trust`, `default-branch`, as by default: the default branch reads its own runs' packs alone. */
    public static function cacheTrustingTheDefaultBranch(): self
    {
        return new self(Json::at('outbreak.cache.trust', 'default-branch'));
    }

    /** `outbreak.cache.trust`, `repository`: the default branch reads the packs any branch wrote too. A loose value. */
    public static function cacheTrustingTheRepository(): self
    {
        return new self(Json::at('outbreak.cache.trust', 'repository'));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
