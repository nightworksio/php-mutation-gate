<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * A mutator set a preset turns on, and the package that registers it, which a
 * run that finds the set missing names for installing (ADR-0021, decision
 * 12).
 */
final readonly class PresetSet
{
    private function __construct(private Name $set, private Name $preset, private string $package)
    {
    }

    public static function of(Name $set, Name $preset, string $package): self
    {
        return new self($set, $preset, $package);
    }

    public function set(): Name
    {
        return $this->set;
    }

    public function preset(): Name
    {
        return $this->preset;
    }

    /** The Composer package that registers the set. */
    public function package(): string
    {
        return $this->package;
    }
}
