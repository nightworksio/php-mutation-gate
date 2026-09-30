<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\File\Digest;

/**
 * Which static analyser checked a mutant (ADR-0020, decisions 6 and 14): its
 * name, its exact version, and a digest of the config it read. A proof's key
 * holds all of it beside the runner's identity.
 */
final readonly class AnalyserIdentity
{
    private function __construct(private string $analyser, private string $version, private Digest $config)
    {
    }

    public static function of(string $analyser, string $version, Digest $config): self
    {
        return new self($analyser, $version, $config);
    }

    public function analyser(): string
    {
        return $this->analyser;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function config(): Digest
    {
        return $this->config;
    }
}
