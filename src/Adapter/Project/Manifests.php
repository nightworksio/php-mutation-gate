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
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;

/**
 * A project's Composer manifests: the `autoload` paths of its root
 * `composer.json`, and the floor each tree declares in the nearest
 * `composer.json` above it, under `extra.mutation-gate` (ADR-0005).
 */
final readonly class Manifests
{
    private function __construct(private string $root)
    {
    }

    public static function in(string $root): self
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

    /** One tree per path, each with the floor the nearest manifest above it declares, in the root package. */
    public function trees(Paths $paths): Trees|CannotJudge
    {
        $trees = [];

        foreach ($paths as $path) {
            $declared = $this->declaredFor($path);

            if ($declared instanceof CannotJudge) {
                return $declared;
            }

            $trees[] = Tree::at($path, $declared, Package::at(Path::root()));
        }

        return Trees::of(...$trees);
    }

    private function declaredFor(Path $tree): Floor|Exempt|Undeclared|CannotJudge
    {
        $start = is_dir(sprintf('%s/%s', $this->root, $tree->value())) ? $tree->value() : dirname($tree->value());

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
        $on = sprintf('%s/%s', $this->root, $file->value());

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
