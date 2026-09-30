<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use Closure;

use function is_string;

use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Proof\Credentials;
use NightWorksIO\MutationGate\Port\ProofStore;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A proof store that needs credentials to write, as `s3` does (ADR-0013
 * decisions 13 and 14). A job that holds them gets the store itself. A job
 * without them opens it read-only: it reads through the `publicUrl` its
 * options name, or reads nothing where they name none, and never sends the
 * store a request it has no credentials to sign.
 */
final readonly class KeyedStore
{
    /** @param Closure(Options): (ProofStore|Invalid) $keyed the store, built from its options */
    private function __construct(
        private Credentials $credentials,
        private Closure $keyed,
        private HttpClientInterface $client,
        private Variables $environment,
    ) {
    }

    /** @param Closure(Options): (ProofStore|Invalid) $keyed the store, built from its options */
    public static function of(
        Credentials $credentials,
        Closure $keyed,
        HttpClientInterface $client,
        Variables $environment,
    ): self {
        return new self($credentials, $keyed, $client, $environment);
    }

    /** The store, where the job holds its credentials; else read-only; or why its options build no store. */
    public function build(Options $options): ProofStore|Invalid
    {
        $store = ($this->keyed)($options);
        $url = $options->text(Key::of('publicUrl'));
        $prefix = $options->text(Key::of('prefix'));

        return match (true) {
            $store instanceof Invalid, $this->credentials->heldIn($this->environment) => $store,
            is_string($url) => PublicLedger::at($this->client, $url, is_string($prefix) ? $prefix : ''),
            default => PublicLedger::nowhere($this->client),
        };
    }
}
