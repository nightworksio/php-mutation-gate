<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** The exact installed version of one package a runner drives, and its source reference. */
final readonly class Version
{
    private function __construct(private string $package, private string $version, private string $reference)
    {
    }

    public static function of(string $package, string $version, string $reference): self
    {
        return new self($package, $version, $reference);
    }

    public function package(): string
    {
        return $this->package;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function reference(): string
    {
        return $this->reference;
    }
}
