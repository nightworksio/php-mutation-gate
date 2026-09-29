<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Tree;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * A project with its own `composer.json` and test suite, in which a runner
 * runs. A repository that is one project is the package at its root.
 */
final readonly class Package
{
    private function __construct(private Path $path)
    {
    }

    public static function at(Path $path): self
    {
        return new self($path);
    }

    public function path(): Path
    {
        return $this->path;
    }
}
