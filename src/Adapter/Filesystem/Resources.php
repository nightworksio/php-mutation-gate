<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function dirname;
use function sprintf;

/** The package's own `resources/` directory, wherever Composer installed the package. */
final readonly class Resources
{
    /** How far above this file the package's root is. */
    private const int PACKAGE_ROOT = 3;

    /** A directory the package ships under `resources/`, on disk. */
    public static function at(Shipped $directory): string
    {
        return sprintf('%s/resources/%s', dirname(__DIR__, self::PACKAGE_ROOT), $directory->value);
    }
}
