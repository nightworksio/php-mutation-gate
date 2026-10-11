<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use LogicException;
use NightWorksIO\MutationGate\Adapter\PhpStan\PhpStan;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;

/** PHPStan in a test's project, as the tests of the PHPStan adapter start it. */
final readonly class PhpStanProjects
{
    /** PHPStan in a project, reading the config the gate hands it, if any. */
    public static function in(string $project, string $options = '{}'): PhpStan
    {
        $phpstan = PhpStan::fromOptions(Configs::options($options), $project, new LocalProcesses(new SystemClock()));

        return $phpstan instanceof PhpStan ? $phpstan : throw new LogicException('No PHPStan.');
    }
}
