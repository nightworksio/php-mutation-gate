<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_string;

use NightWorksIO\MutationGate\Core\NotGiven;

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

    /** The store this one is for a run that names this registry, or none, under the package's action or not. */
    public function resolved(string|NotGiven $registry, bool $underAction): self
    {
        return match (true) {
            $this !== self::Auto => $this === self::Oci && $registry instanceof NotGiven ? self::None : $this,
            is_string($registry) => self::Oci,
            $underAction => self::Actions,
            default => self::None,
        };
    }

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::Auto => 'Outbreak::cacheStoredAuto()',
            self::None => 'Outbreak::cacheStoredNowhere()',
            self::Actions => 'Outbreak::cacheStoredInActions()',
            self::Oci => 'Outbreak::cacheStoredInRegistry()',
        };
    }
}
