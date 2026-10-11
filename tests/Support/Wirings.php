<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Fakes\ExtensionFake;

use function sprintf;

/** The registry and detected project the tests of the wiring build. */
final readonly class Wirings
{
    /** This package's registry, with the fake runner beside it. */
    public static function registry(): Extensions
    {
        return new ExtensionFake()->extend(new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER))));
    }

    /** What zero-config finds of the fixture project, which installs no static analyser. */
    public static function detected(): Detected
    {
        return new Detected(Directory::at(Flows::project()), Directory::at(sprintf('%s/vendor', Flows::project())));
    }
}
