<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_any;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\Composer\AutoloadSection;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/**
 * Where the tests of each package load support from besides their
 * directories of tests: each directory or file the package's
 * `composer.json` names under `autoload-dev`, as the project's root and as
 * the package's own directory spell it, and what each PHP file there holds
 * for reach to read. A manifest that cannot be read names none.
 *
 * @internal
 */
final readonly class SupportPlaces
{
    /** @param list<array{Path, Path}> $places each place, spelt from the root and from its package's directory */
    private function __construct(private array $places)
    {
    }

    public static function read(Directory $project, Trees $trees): self
    {
        $places = [];

        foreach (self::packagesOf($trees) as $package) {
            foreach (self::namedIn($project, $package) as $place) {
                $places[] = [$place, $place->relativeTo($package)];
            }
        }

        return new self($places);
    }

    /**
     * These sources, with each PHP file of these, outside the test
     * directories, that is in one of the places, as it is on disk.
     */
    public function readInto(Sources $sources, Fingerprints $outside, Directory $project): Sources
    {
        foreach ($outside as $file) {
            $contents = $this->holds($file->path()) ? $project->read($file->path()) : Missing::at($file->path());
            $sources = $contents instanceof Contents ? $sources->withNow($file->path(), $contents) : $sources;
        }

        return $sources;
    }

    /** Each place, spelt from its package's directory. */
    public function inPackages(): Paths
    {
        $inPackages = Paths::none();

        foreach ($this->places as [, $inPackage]) {
            $inPackages = $inPackages->with($inPackage);
        }

        return $inPackages;
    }

    /** Whether a PHP file, spelt from the root, is in one of the places. */
    private function holds(Path $file): bool
    {
        return $file->isPhp() && array_any($this->places, static fn(array $place): bool => $file->within($place[0]));
    }

    /** @return array<string, Path> the project's root and each package a tree is in, by its path */
    private static function packagesOf(Trees $trees): array
    {
        $packages = [Path::root()->value() => Path::root()];

        foreach ($trees as $tree) {
            $packages[$tree->package()->path()->value()] = $tree->package()->path();
        }

        return $packages;
    }

    /** What a package's manifest names under `autoload-dev`, spelt from the root; none where it cannot be read. */
    private static function namedIn(Directory $project, Path $package): Paths
    {
        $contents = $project->read(Manifest::fileIn($package));
        $manifest = $contents instanceof Contents ? Manifest::decode($contents, $package) : $contents;

        return $manifest instanceof Manifest ? $manifest->autoloaded(AutoloadSection::Development) : Paths::none();
    }
}
