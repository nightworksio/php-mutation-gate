<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Gcs;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Required;
use NightWorksIO\MutationGate\Core\Config\StoreOption;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What the `gcs` store's options say, as the definition reads them with
 * their defaults: the `bucket`, the `prefix`, and the `publicUrl` a job
 * without credentials reads the default branch's ledger from
 * (ADR-0028 decisions 1 and 4).
 */
final readonly class GcsOptions
{
    private function __construct(private string $bucket, private string $prefix, private string|NotGiven $publicUrl)
    {
    }

    public static function read(Options $options): self|Invalid
    {
        $bucket = Required::text($options, StoreOption::Bucket->value);
        $prefix = Required::text($options, StoreOption::Prefix->value);
        $publicUrl = $options->text(Key::of(StoreOption::PublicUrl->value));

        return match (true) {
            $bucket instanceof Problem => Invalid::because($bucket),
            $prefix instanceof Problem => Invalid::because($prefix),
            $publicUrl instanceof Problem => Invalid::because($publicUrl),
            default => new self($bucket, $prefix, $publicUrl),
        };
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** The URL a job without credentials reads the default branch's ledger from; none where none is named. */
    public function publicUrl(): string|NotGiven
    {
        return $this->publicUrl;
    }
}
