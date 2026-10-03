<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use Composer\InstalledVersions;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\ThisPackage;

/** The gate as Composer installed it: its version and the commit it was installed from. */
final readonly class InstalledGate
{
    public static function version(): Version
    {
        return Version::of(
            ThisPackage::COMPOSER,
            InstalledVersions::getPrettyVersion(ThisPackage::COMPOSER) ?? '',
            InstalledVersions::getReference(ThisPackage::COMPOSER) ?? '',
        );
    }
}
