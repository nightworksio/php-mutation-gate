<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * Where `outbreak cache` keeps its packs of proofs by their keys (ADR-0131):
 * `outbreak.cache.store`.
 */
enum CacheStore: string
{
    /** An OCI registry where one is named, the Actions cache under the package's action, and none elsewhere. */
    case Auto = 'auto';

    /** No packs: a run proves from its ledgers alone. */
    case None = 'none';

    /** Each pack an entry of the Actions cache, in the scope of the run that saved it. */
    case Actions = 'actions';

    /** Each pack an OCI artifact in the repository `outbreak.cache.registry` names. */
    case Oci = 'oci';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Auto => 'OutbreakCache::storedAuto()',
            self::None => 'OutbreakCache::storedNowhere()',
            self::Actions => 'OutbreakCache::storedInActions()',
            self::Oci => 'OutbreakCache::storedInRegistry()',
        };
    }
}
