<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Adapter\S3\BucketOptions;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\NotGiven;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The `s3` store as a job without its credentials opens it (ADR-0013
 * decisions 13 and 14): read-only, through the `publicUrl` and under the
 * `prefix` its options name, or reading nothing where they name no URL.
 */
final readonly class PublicBucket
{
    public function __construct(private HttpClientInterface $client)
    {
    }

    /** The store, read-only; or why its options build none. */
    public function build(Options $options): PublicLedger|Invalid
    {
        $bucket = BucketOptions::read($options);

        if ($bucket instanceof Invalid) {
            return $bucket;
        }

        $url = $bucket->publicUrl();

        return $url instanceof NotGiven
            ? PublicLedger::nowhere($this->client)
            : PublicLedger::at($this->client, $url, $bucket->prefix());
    }
}
