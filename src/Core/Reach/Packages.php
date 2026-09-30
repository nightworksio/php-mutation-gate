<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_pop;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/**
 * The packages the trees belong to, the project at the root among them, and
 * which of them depend on which through path repositories.
 */
final readonly class Packages
{
    /** @param array<string, Package> $packages every package, by its path */
    private function __construct(private array $packages)
    {
    }

    public static function of(Trees $trees): self
    {
        $packages = [Path::root()->value() => Package::at(Path::root())];

        foreach ($trees as $tree) {
            $packages[$tree->package()->path()->value()] = $tree->package();
        }

        return new self($packages);
    }

    /** The package a path is in: the innermost one whose directory holds it. */
    public function holding(Path $path): Package
    {
        $holding = $this->packages[Path::root()->value()];

        foreach ($this->packages as $package) {
            $holding = $path->within($package->path()) && $package->path()->within($holding->path())
                ? $package
                : $holding;
        }

        return $holding;
    }

    /** A package, and every package that depends on it, directly or through others. */
    public function withDependents(Package $package): Paths
    {
        $reached = Paths::of($package->path());
        $pending = [$package->path()];

        while ($pending !== []) {
            $dependency = array_pop($pending);

            foreach ($this->packages as $other) {
                if ($other->dependencies()->has($dependency) && ! $reached->has($other->path())) {
                    $reached = $reached->with($other->path());
                    $pending[] = $other->path();
                }
            }
        }

        return $reached;
    }
}
