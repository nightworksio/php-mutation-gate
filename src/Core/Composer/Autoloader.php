<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * Composer's autoloader, which it writes into the vendor directory it installs into.
 *
 * @internal
 */
final readonly class Autoloader
{
    private const string FILE = 'autoload.php';

    /** Where the autoloader is in a vendor directory. */
    public static function fileIn(Path $vendor): Path
    {
        return $vendor->child(Path::of(self::FILE));
    }
}
