<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use Composer\InstalledVersions;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;

/**
 * The gate as Composer installed it: its version and the commit it was installed from.
 *
 * @internal
 */
final readonly class InstalledGate
{
    public static function version(): Version
    {
        return Version::of(
            ThisPackage::COMPOSER,
            sprintf('%s', InstalledVersions::getPrettyVersion(ThisPackage::COMPOSER)),
            sprintf('%s', InstalledVersions::getReference(ThisPackage::COMPOSER)),
        );
    }
}
