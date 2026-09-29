<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Composer;

use function array_any;
use function array_key_exists;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Tree\Package;

use function sprintf;

/**
 * The packages of a repository: the project at its root, each directory a
 * `packages` glob matches that has a `composer.json`, and each of the root's
 * path repositories that has a PHPUnit config too; each with the packages it
 * requires among them.
 */
final readonly class Packages
{
    /** What a package declares itself in. */
    private const string MANIFEST = 'composer.json';

    /** What a package's PHPUnit config may be called. */
    private const array PHPUNIT = ['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'];

    /** @param list<Package> $packages the root's first */
    private function __construct(private array $packages)
    {
    }

    /** @param list<string> $globs the directories of packages, as shell globs from the root */
    public static function in(Disk $disk, array $globs): self|CannotJudge
    {
        $root = Manifest::in($disk, Path::root());

        if ($root instanceof CannotJudge) {
            return $root;
        }

        $directories = [Path::root()];

        foreach ($globs as $glob) {
            $directories = [...$directories, ...self::withAny($disk, $disk->directories($glob), [self::MANIFEST])];
        }

        foreach ($root instanceof Manifest ? $root->pathRepositories() : [] as $repository) {
            $manifested = self::withAny($disk, $disk->directories($repository), [self::MANIFEST]);
            $directories = [...$directories, ...self::withAny($disk, $manifested, self::PHPUNIT)];
        }

        return self::related($disk, $directories);
    }

    /** The package a path is in: the innermost one whose directory holds it. */
    public function holding(Path $path): Package
    {
        $holding = $this->packages[0];

        foreach ($this->packages as $package) {
            $holding = $path->within($package->path()) && $package->path()->within($holding->path())
                ? $package
                : $holding;
        }

        return $holding;
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
        $named = [];

        foreach ($directories as $directory) {
            $manifest = Manifest::in($disk, $directory);

            if ($manifest instanceof CannotJudge) {
                return $manifest;
            }

            $manifests[$directory->value()] = [$directory, $manifest];
            $named = $manifest instanceof Manifest ? [...$named, $manifest->name() => $directory] : $named;
        }

        $packages = [];

        foreach ($manifests as [$directory, $manifest]) {
            $package = Package::at($directory);

            foreach ($manifest instanceof Manifest ? $manifest->requires() : [] as $required) {
                $package = array_key_exists($required, $named) ? $package->dependingOn($named[$required]) : $package;
            }

            $packages[] = $package;
        }

        return new self($packages);
    }

    /**
     * The directories that hold any of some files.
     *
     * @param  list<Path>   $directories
     * @param  list<string> $files
     * @return list<Path>
     */
    private static function withAny(Disk $disk, array $directories, array $files): array
    {
        $found = [];

        foreach ($directories as $directory) {
            $found = self::holdsAny($disk, $directory, $files) ? [...$found, $directory] : $found;
        }

        return $found;
    }

    /** @param list<string> $files */
    private static function holdsAny(Disk $disk, Path $directory, array $files): bool
    {
        return array_any(
            $files,
            static fn(string $file): bool => $disk->isFile(Path::of(sprintf('%s/%s', $directory->value(), $file))),
        );
    }
}
