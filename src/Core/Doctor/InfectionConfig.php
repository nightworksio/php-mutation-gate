<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

/**
 * An Infection config file in the project, and what in it the gate reads
 * elsewhere: a `minMsi`, which floors replace, and Infection's own ignores,
 * which `ignores.entries` replaces (ADR-0016).
 */
final readonly class InfectionConfig
{
    private function __construct(private string $file, private bool $minMsi, private bool $ignores)
    {
    }

    public static function in(string $file, bool $minMsi, bool $ignores): self
    {
        return new self($file, $minMsi, $ignores);
    }

    public function file(): string
    {
        return $this->file;
    }

    public function setsMinMsi(): bool
    {
        return $this->minMsi;
    }

    public function ignores(): bool
    {
        return $this->ignores;
    }
}
