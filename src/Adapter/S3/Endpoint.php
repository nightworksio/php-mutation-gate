<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\S3;

/** The URL of a store that speaks S3 other than AWS's own: R2's, or MinIO's. */
final readonly class Endpoint
{
    private function __construct(private string $url)
    {
    }

    public static function at(string $url): self
    {
        return new self($url);
    }

    public function url(): string
    {
        return $this->url;
    }
}
