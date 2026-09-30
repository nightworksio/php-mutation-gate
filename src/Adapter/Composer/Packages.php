<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Composer;

use function array_any;
use function array_filter;
use function array_key_exists;
use function array_values;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\Composer\Names;
use NightWorksIO\MutationGate\Core\Composer\Unnamed;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Tree\Package;

/**
 * The packages of a repository: the project at its root, each directory a
 * `packages` glob matches that has a `composer.json`, and each of the root's
 * path repositories that has a PHPUnit config too; each with the packages it
 * requires among them.
 */
final readonly class Packages
{
    /** @param list<Package> $packages the root's first */
    private function __construct(private array $packages)
    {
    }

    /** @param list<string> $globs the directories of packages, as globs from the root */
    public static function in(Disk $disk, array $globs): self|CannotJudge
    {
        $root = $disk->manifestIn(Path::root());
        $directories = [Path::root()];

        foreach ($globs as $glob) {
            $directories = [...$directories, ...self::withManifest($disk, $disk->directoriesMatching(Glob::of($glob)))];
        }

        foreach ($root instanceof Manifest ? $root->pathRepositories() : Paths::none() as $repository) {
            $manifested = self::withManifest($disk, $disk->directories($repository->value()));
            $directories = [...$directories, ...self::withPhpUnitConfig($disk, $manifested)];
        }

        return self::related($disk, $directories);
    }

    /** The package a path is in: the innermost one whose directory holds it. */
    public function holding(Path $path): Package
    {
        return Package::holding($path, ...$this->packages);
    }

    /** @return list<Path> */
    public function directories(): array
    {
        $directories = [];

        foreach ($this->packages as $package) {
            $directories[] = $package->path();
        }

        return $directories;
    }

    /**
     * Each package, depending on the others its manifest requires by name.
     *
     * @param non-empty-list<Path> $directories the root's first
     */
    private static function related(Disk $disk, array $directories): self|CannotJudge
    {
        $manifests = [];

        foreach ($directories as $directory) {
            $manifest = $disk->manifestIn($directory);

            if ($manifest instanceof CannotJudge) {
                return $manifest;
            }

            $manifests[$directory->value()] = $manifest;
        }

        $held = ByPath::mapping(
            Paths::of(...$directories),
            static fn(Path $directory): Manifest|Missing => $manifests[$directory->value()],
        );
        $named = self::named($held);
        $packages = [];

        foreach ($held as $directory => $manifest) {
            $packages[] = self::package($directory, $manifest, $named);
        }

        return new self($packages);
    }

    /**
     * The directory of each package that has a manifest, by the name it declares.
     *
     * @param  ByPath<Manifest|Missing> $manifests each directory's manifest
     * @return array<string, Path>
     */
    private static function named(ByPath $manifests): array
    {
        $named = [];

        foreach ($manifests as $directory => $manifest) {
            $name = $manifest instanceof Manifest ? $manifest->name() : Unnamed::package();

            if (is_string($name)) {
                $named[$name] = $directory;
            }
        }

        return $named;
    }

    /**
     * The package at a directory, depending on each of the named packages its manifest requires.
     *
     * @param array<string, Path> $named
     */
    private static function package(Path $directory, Manifest|Missing $manifest, array $named): Package
    {
        $package = Package::at($directory);

        foreach ($manifest instanceof Manifest ? $manifest->requires() : Names::of() as $required) {
            $package = array_key_exists($required, $named) ? $package->dependingOn($named[$required]) : $package;
        }

        return $package;
    }

    /**
     * The directories that hold a `composer.json`.
     *
     * @param  list<Path> $directories
     * @return list<Path>
     */
    private static function withManifest(Disk $disk, array $directories): array
    {
        return array_values(array_filter(
            $directories,
            static fn(Path $directory): bool => $disk->isFile(Manifest::fileIn($directory)),
        ));
    }

    /**
     * The directories that hold a PHPUnit config, whichever name it has.
     *
     * @param  list<Path> $directories
     * @return list<Path>
     */
    private static function withPhpUnitConfig(Disk $disk, array $directories): array
    {
        return array_values(array_filter(
            $directories,
            static fn(Path $directory): bool => array_any(
                [...PhpUnitConfig::candidatesIn($directory)],
                $disk->isFile(...),
            ),
        ));
    }
}
