<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * The modules of a repository (ADR-0005 rule 7, ADR-0108): each directory with a
 * `composer.json` of its own inside a package, sharing its suite, that is not
 * a package itself.
 *
 * @internal
 */
final readonly class Modules
{
    /** The directory of each module among these files version control lists, with packages at these directories. */
    public static function among(Paths $listed, Paths $packages): Paths
    {
        $modules = [];

        foreach ($listed as $file) {
            $directory = $file->directory();
            $modules = $file->equals(Manifest::fileIn($directory)) && ! self::isPackage($directory, $packages)
                ? [...$modules, $directory]
                : $modules;
        }

        return Paths::of(...$modules);
    }

    private static function isPackage(Path $directory, Paths $packages): bool
    {
        return $packages->has($directory) || $directory->equals(Path::root());
    }
}
