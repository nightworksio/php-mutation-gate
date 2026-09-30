<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function rtrim;
use function sprintf;

/**
 * Where a file or a directory is on disk, as the gate opens it: a root's
 * spelling joined with a path under it, or an absolute path as it is.
 */
final readonly class DiskPath
{
    private function __construct(private string $value)
    {
    }

    public static function of(string $path): self
    {
        return new self($path);
    }

    /** The path of an entry inside this directory, by its name. */
    public function child(string $name): self
    {
        return new self(sprintf('%s/%s', rtrim($this->value, '/'), $name));
    }

    public function value(): string
    {
        return $this->value;
    }
}
