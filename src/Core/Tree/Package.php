<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Tree;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * A project with its own `composer.json` and test suite, in which a runner
 * runs, and the packages it requires through a path repository. A repository
 * that is one project is the package at its root.
 */
final readonly class Package
{
    private function __construct(private Path $path, private Paths $dependencies)
    {
    }

    public static function at(Path $path): self
    {
        return new self($path, Paths::none());
    }

    /** This package, requiring the package at another path through a path repository. */
    public function dependingOn(Path $package): self
    {
        return new self($this->path, $this->dependencies->with($package));
    }

    public function path(): Path
    {
        return $this->path;
    }

    /** The paths of the packages this one requires through a path repository. */
    public function dependencies(): Paths
    {
        return $this->dependencies;
    }
}
