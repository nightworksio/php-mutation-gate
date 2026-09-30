<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Composer;

use function dirname;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Port\TreeSource;

/**
 * One tree per path the autoload of a manifest names: the manifests a glob
 * matches, and every package's own. Each tree declares the floor of the
 * nearest `composer.json` at or above it that declares one, and belongs to the
 * innermost package around it.
 */
final readonly class ComposerTrees implements TreeSource
{
    /**
     * @param list<string> $manifests shell globs of the manifests whose autoload names trees, from the root
     * @param list<string> $packages  shell globs of the packages' directories, from the root
     */
    private function __construct(private Disk $disk, private array $manifests, private array $packages)
    {
    }

    /**
     * @param list<string> $manifests shell globs of the manifests whose autoload names trees, from the root
     * @param list<string> $packages  shell globs of the packages' directories, from the root
     */
    public static function at(string $root, array $manifests, array $packages): self
    {
        return new self(Disk::at($root), $manifests, $packages);
    }

    public function trees(): Trees|CannotJudge
    {
        $packages = Packages::in($this->disk, $this->packages);

        return $packages instanceof CannotJudge ? $packages : $this->treesIn($packages);
    }

    private function treesIn(Packages $packages): Trees|CannotJudge
    {
        $trees = [];

        foreach ($this->manifestDirectories($packages) as $directory) {
            $found = $this->treesOf($directory, $packages);

            if ($found instanceof CannotJudge) {
                return $found;
            }

            foreach ($found as $tree) {
                $trees[$tree->path()->value()] = $tree;
            }
        }

        return Trees::of(...$trees);
    }

    /**
     * The trees the manifest in a directory names, if there is one.
     *
     * @return list<Tree>|CannotJudge
     */
    private function treesOf(Path $directory, Packages $packages): array|CannotJudge
    {
        $manifest = $this->disk->manifestIn($directory);

        if ($manifest instanceof CannotJudge) {
            return $manifest;
        }

        $trees = [];

        foreach ($manifest instanceof Manifest ? $manifest->autoloaded() : Paths::none() as $path) {
            $tree = $this->treeAt($path, $packages);

            if ($tree instanceof CannotJudge) {
                return $tree;
            }

            $trees[] = $tree;
        }

        return $trees;
    }

    /**
     * The directory of every manifest a glob matches, and of every package.
     *
     * @return list<Path>
     */
    private function manifestDirectories(Packages $packages): array
    {
        $directories = [];

        foreach ($this->manifests as $glob) {
            foreach ($this->disk->files($glob) as $file) {
                $directories[] = Path::of(dirname($file->value()));
            }
        }

        return [...$directories, ...$packages->directories()];
    }

    private function treeAt(Path $path, Packages $packages): Tree|CannotJudge
    {
        $above = $this->manifestsAbove($path);

        return $above instanceof CannotJudge ? $above : $this->treeFrom($path, $above, $packages->holding($path));
    }

    /** @param list<Manifest> $above the manifests at and above the tree, the nearest first */
    private function treeFrom(Path $path, array $above, Package $package): Tree|CannotJudge
    {
        $declared = $this->declared($above);
        $newCode = $this->newCode($above);

        return match (true) {
            $declared instanceof CannotJudge => $declared,
            $newCode instanceof CannotJudge => $newCode,
            $newCode instanceof Floor => Tree::at($path, $declared, $package)->withNewCodeFloor($newCode),
            default => Tree::at($path, $declared, $package),
        };
    }

    /**
     * The manifests at and above a path, the nearest first.
     *
     * @return list<Manifest>|CannotJudge
     */
    private function manifestsAbove(Path $path): array|CannotJudge
    {
        $manifests = [];
        $directory = $path;

        do {
            $manifest = $this->disk->manifestIn($directory);

            if ($manifest instanceof CannotJudge) {
                return $manifest;
            }

            $manifests = $manifest instanceof Manifest ? [...$manifests, $manifest] : $manifests;
            $above = Path::of(dirname($directory->value()));
            $reached = $directory->equals($above);
            $directory = $above;
        } while (! $reached);

        return $manifests;
    }

    /** @param list<Manifest> $above */
    private function declared(array $above): Floor|Exempt|Undeclared|CannotJudge
    {
        foreach ($above as $manifest) {
            $floor = $manifest->gate()->floor();

            if (! $floor instanceof Undeclared) {
                return $floor;
            }
        }

        return Undeclared::floor();
    }

    /** @param list<Manifest> $above */
    private function newCode(array $above): Floor|Undeclared|CannotJudge
    {
        foreach ($above as $manifest) {
            $floor = $manifest->gate()->newCodeFloor();

            if (! $floor instanceof Undeclared) {
                return $floor;
            }
        }

        return Undeclared::floor();
    }
}
