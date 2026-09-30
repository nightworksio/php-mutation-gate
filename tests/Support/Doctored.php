<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Runtime\PhpProbe;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Doctor\Observed;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;

use function sprintf;

/** What `doctor` observes of a project, with a PHP a test chose in place of the one running the tests. */
final class Doctored
{
    /** @param array<string, string> $environment */
    public static function observed(string $project, string $php, array $environment = []): Observed
    {
        $extensions = new FirstParty()->extend(new Extensions(Origin::of(FirstParty::PACKAGE)));
        $detected = new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)));

        return new Observed(
            $project,
            $extensions,
            new Effective($project, $extensions, $detected, new DateTimeImmutable(Configs::NOW)),
            $detected,
            PhpProbe::of($php, $environment),
        );
    }
}
