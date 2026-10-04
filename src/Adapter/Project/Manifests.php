<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function dirname;
use function file_get_contents;
use function is_dir;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/**
 * A project's Composer manifests: the `autoload` paths of its root
 * `composer.json`, the floor each tree declares in the nearest
 * `composer.json` above it, under `extra.mutation-gate` (ADR-0005), and the
 * floor the root's declares for its security set (ADR-0021).
 */
final readonly class Manifests
{
    private function __construct(private Root $root)
    {
    }

    public static function in(Root $root): self
    {
        return new self($root);
    }

    /** Every path the root `composer.json` autoloads, not `autoload-dev`, in the order it lists them. */
    public function autoloaded(): Paths|CannotJudge
    {
        $manifest = $this->read(Path::root());

        return match (true) {
            $manifest instanceof Manifest => $manifest->autoloaded(),
            $manifest instanceof CannotJudge => $manifest,
            default => Paths::none(),
        };
    }

    /**
     * One tree per path, each with the floor the nearest manifest above it
     * declares, in the root package, with the floor the root manifest
     * declares for its security set (ADR-0021).
     */
    public function trees(Paths $paths): Trees|CannotJudge
    {
        $package = $this->package();

        if ($package instanceof CannotJudge) {
            return $package;
        }

        $trees = [];

        foreach ($paths as $path) {
            $declared = $this->declaredFor($path);
            $module = $this->moduleSecurityFloor($path);

            if ($declared instanceof CannotJudge || $module instanceof CannotJudge) {
                return $declared instanceof CannotJudge ? $declared : $module;
            }

            $trees[] = Tree::at($path, $declared, $package);
        }

        return Trees::of(...$trees);
    }

    /** The root package, with the floor its manifest declares for its security set. */
    private function package(): Package|CannotJudge
    {
        $manifest = $this->read(Path::root());
        $floor = $manifest instanceof Manifest ? $manifest->gate()->securityFloor(ofAPackage: true) : $manifest;

        return match (true) {
            $floor instanceof CannotJudge => $floor,
            $floor instanceof Floor => Package::at(Path::root())->withSecurityFloor($floor),
            default => Package::at(Path::root()),
        };
    }

    /**
     * Why a manifest above a tree below the root, which is no package's,
     * cannot declare `securityFloor`, where one does.
     */
    private function moduleSecurityFloor(Path $tree): CannotJudge|NotGiven
    {
        $start = is_dir($this->root->at($tree)->value()) ? $tree->value() : dirname($tree->value());

        foreach (self::upFrom($start) as $directory) {
            $at = Path::of($directory);
            $manifest = $at->equals(Path::root()) ? Missing::at($tree) : $this->read($at);
            $floor = $manifest instanceof Manifest ? $manifest->gate()->securityFloor(ofAPackage: false) : $manifest;

            if ($floor instanceof CannotJudge) {
                return $floor;
            }
        }

        return NotGiven::value();
    }

    private function declaredFor(Path $tree): Floor|Exempt|Undeclared|CannotJudge
    {
        $start = is_dir($this->root->at($tree)->value()) ? $tree->value() : dirname($tree->value());

        foreach (self::upFrom($start) as $directory) {
            $declared = $this->declaredIn(Path::of($directory));

            if (! $declared instanceof Undeclared) {
                return $declared;
            }
        }

        return Undeclared::floor();
    }

    private function declaredIn(Path $directory): Floor|Exempt|Undeclared|CannotJudge
    {
        $manifest = $this->read($directory);

        return match (true) {
            $manifest instanceof Manifest => $manifest->gate()->floor(),
            $manifest instanceof CannotJudge => $manifest,
            default => Undeclared::floor(),
        };
    }

    private function read(Path $directory): Manifest|Missing|CannotJudge
    {
        $file = Manifest::fileIn($directory);
        $on = $this->root->at($file)->value();

        return is_file($on)
            ? Manifest::decode(Contents::of((string) file_get_contents($on)), $directory)
            : Missing::at($file);
    }

    /**
     * A directory and every directory above it, to the top.
     *
     * @return list<string>
     */
    private static function upFrom(string $directory): array
    {
        $parent = dirname($directory);

        return $parent === $directory ? [$directory] : [$directory, ...self::upFrom($parent)];
    }
}
