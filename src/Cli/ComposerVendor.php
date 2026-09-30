<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Composer\Disk;
use NightWorksIO\MutationGate\Adapter\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;
use function str_starts_with;

/**
 * Where Composer installed a project's packages, as Composer decides it:
 * `COMPOSER_VENDOR_DIR`, then the manifest's `config.vendor-dir`, then
 * `vendor`. The Pest runner reads Pest there, and `pest:patch` patches it.
 */
final readonly class ComposerVendor
{
    /** The vendor directory of the project in this directory, as the project spells it. */
    public static function of(string $project): Path
    {
        $overridden = getenv('COMPOSER_VENDOR_DIR');
        $manifest = Manifest::in(Disk::at($project), Path::root());

        return match (true) {
            is_string($overridden) && $overridden !== '' => Path::of($overridden),
            $manifest instanceof Manifest => $manifest->vendorDirectory(),
            default => Path::of(Manifest::VENDOR),
        };
    }

    /** The vendor directory of the project in this directory, where it is on disk. */
    public static function on(string $project): string
    {
        $vendor = self::of($project)->value();

        return str_starts_with($vendor, '/') ? $vendor : sprintf('%s/%s', $project, $vendor);
    }
}
