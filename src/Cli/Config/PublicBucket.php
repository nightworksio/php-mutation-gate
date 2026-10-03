<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use NightWorksIO\MutationGate\Adapter\Azure\ContainerOptions;
use NightWorksIO\MutationGate\Adapter\Gcs\GcsOptions;
use NightWorksIO\MutationGate\Adapter\Http\PublicLedger;
use NightWorksIO\MutationGate\Adapter\S3\BucketOptions;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\NotGiven;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An object store as a job without its credentials opens it (ADR-0013
 * decisions 13 and 14, ADR-0028 decision 4): `s3`, `gcs` or `azure`,
 * read-only, through the `publicUrl` and under the `prefix` its options name,
 * or reading nothing where they name no URL.
 */
final readonly class PublicBucket
{
    public function __construct(private HttpClientInterface $client)
    {
    }

    /** The `s3` store, read-only; or why its options build none. */
    public function s3(Options $options): PublicLedger|Invalid
    {
        $bucket = BucketOptions::read($options);

        return $bucket instanceof Invalid ? $bucket : $this->reading($bucket->publicUrl(), $bucket->prefix());
    }

    /** The `gcs` store, read-only; or why its options build none. */
    public function gcs(Options $options): PublicLedger|Invalid
    {
        $bucket = GcsOptions::read($options);

        return $bucket instanceof Invalid ? $bucket : $this->reading($bucket->publicUrl(), $bucket->prefix());
    }

    /** The `azure` store, read-only; or why its options build none. */
    public function azure(Options $options): PublicLedger|Invalid
    {
        $container = ContainerOptions::read($options);

        return $container instanceof Invalid
            ? $container
            : $this->reading($container->publicUrl(), $container->prefix());
    }

    private function reading(string|NotGiven $url, string $prefix): PublicLedger
    {
        return $url instanceof NotGiven
            ? PublicLedger::nowhere($this->client)
            : PublicLedger::at($this->client, $url, $prefix);
    }
}
