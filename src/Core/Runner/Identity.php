<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\File\Digest;

/**
 * Which runner judged a mutant: its name, the exact version of every package
 * it drives, and a digest of the PHP it runs on (version, extensions, ini,
 * operating system and architecture). All of it goes into a proof's key.
 */
final readonly class Identity
{
    private function __construct(private string $runner, private Versions $versions, private Digest $platform)
    {
    }

    public static function of(string $runner, Versions $versions, Digest $platform): self
    {
        return new self($runner, $versions, $platform);
    }

    public function runner(): string
    {
        return $this->runner;
    }

    public function versions(): Versions
    {
        return $this->versions;
    }

    public function platform(): Digest
    {
        return $this->platform;
    }
}
