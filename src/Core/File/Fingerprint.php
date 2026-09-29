<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

/** A file and the digest of what it holds. */
final readonly class Fingerprint
{
    private function __construct(private Path $path, private Digest $digest)
    {
    }

    public static function of(Path $path, Digest $digest): self
    {
        return new self($path, $digest);
    }

    public function path(): Path
    {
        return $this->path;
    }

    public function digest(): Digest
    {
        return $this->digest;
    }
}
