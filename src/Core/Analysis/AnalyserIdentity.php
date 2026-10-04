<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\File\Digest;

/**
 * Which static analyser checked a mutant (ADR-0020, decisions 6 and 14): its
 * name, its exact version, and a digest of its config: of the configuration
 * it resolves, with every file that references, where it can say them, and
 * otherwise of its config file. A proof's key holds all of it beside the
 * runner's identity.
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

    /** This analyser, its config's digest that of the configuration it resolves ({@see AnalyserSettings}). */
    public function configuredBy(Digest $resolved): self
    {
        return new self($this->analyser, $this->version, $resolved);
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
