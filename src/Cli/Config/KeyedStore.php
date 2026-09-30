<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use Closure;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Proof\Credentials;
use NightWorksIO\MutationGate\Port\ProofStore;

/**
 * A proof store that needs credentials to write, as `s3` does (ADR-0013
 * decisions 13 and 14). A job that holds them gets the store itself. A job
 * without them gets the store read-only, as the store's own options build
 * it, and never sends the store a request it has no credentials to sign.
 */
final readonly class KeyedStore
{
    /**
     * @param Closure(Options): (ProofStore|Invalid) $keyed    the store, built from its options
     * @param Closure(Options): (ProofStore|Invalid) $keyless  the store read-only, built from the same options
     */
    private function __construct(
        private Credentials $credentials,
        private Closure $keyed,
        private Closure $keyless,
        private Variables $environment,
    ) {
    }

    /**
     * @param Closure(Options): (ProofStore|Invalid) $keyed    the store, built from its options
     * @param Closure(Options): (ProofStore|Invalid) $keyless  the store read-only, built from the same options
     */
    public static function of(
        Credentials $credentials,
        Closure $keyed,
        Closure $keyless,
        Variables $environment,
    ): self {
        return new self($credentials, $keyed, $keyless, $environment);
    }

    /** The store, where the job holds its credentials; else read-only; or why its options build no store. */
    public function build(Options $options): ProofStore|Invalid
    {
        return $this->credentials->heldIn($this->environment) ? ($this->keyed)($options) : ($this->keyless)($options);
    }
}
