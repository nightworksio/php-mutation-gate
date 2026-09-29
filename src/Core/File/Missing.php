<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

/** A file that is not there. */
final readonly class Missing
{
    private function __construct(private Path $path) {}

    public static function at(Path $path): self
    {
        return new self($path);
    }

    public function path(): Path
    {
        return $this->path;
    }
}
