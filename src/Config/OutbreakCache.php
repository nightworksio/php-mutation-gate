<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * Where `outbreak cache` keeps proofs by their keys, and whose a run trusts
 * (ADR-0131): `outbreak.cache`.
 */
final readonly class OutbreakCache implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /**
     * `outbreak.cache.store`, `auto`, as by default: a registry where one is named, the Actions cache under the action.
     */
    public static function storedAuto(): self
    {
        return new self(Json::at('outbreak.cache.store', 'auto'));
    }

    /** `outbreak.cache.store`, `none`: a run proves from its ledgers alone. */
    public static function storedNowhere(): self
    {
        return new self(Json::at('outbreak.cache.store', 'none'));
    }

    /** `outbreak.cache.store`, `actions`: each pack an entry of the Actions cache. */
    public static function storedInActions(): self
    {
        return new self(Json::at('outbreak.cache.store', 'actions'));
    }

    /** `outbreak.cache.store`, `oci`: each pack an artifact in the repository `outbreak.cache.registry` names. */
    public static function storedInRegistry(): self
    {
        return new self(Json::at('outbreak.cache.store', 'oci'));
    }

    /** `outbreak.cache.registry`: the OCI repository the packs live in, such as `ghcr.io/owner/proofs`. */
    public static function registry(string $repository): self
    {
        return new self(Json::at('outbreak.cache.registry', $repository));
    }

    /** `outbreak.cache.trust`, `default-branch`, as by default: the default branch reads its own runs' packs alone. */
    public static function trustingTheDefaultBranch(): self
    {
        return new self(Json::at('outbreak.cache.trust', 'default-branch'));
    }

    /** `outbreak.cache.trust`, `repository`: the default branch reads the packs any branch wrote too. A loose value. */
    public static function trustingTheRepository(): self
    {
        return new self(Json::at('outbreak.cache.trust', 'repository'));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
