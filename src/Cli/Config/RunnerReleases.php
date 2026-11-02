<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use NightWorksIO\MutationGate\Adapter\Codeception\Installed as CodeceptionReleases;
use NightWorksIO\MutationGate\Adapter\Infection\Installed as InfectionReleases;
use NightWorksIO\MutationGate\Adapter\Pest\Installed as PestReleases;
use NightWorksIO\MutationGate\Adapter\PhpSpec\Installed as PhpSpecReleases;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Installed as PhpUnitReleases;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Runner\Versions;

/**
 * The releases a built-in runner drives, as Composer's `installed.json`
 * lists them, or why it drives none of them: read as each runner reads them
 * before it starts, without starting anything.
 *
 * @internal
 */
final readonly class RunnerReleases
{
    public static function of(BuiltinRunner $runner, string $manifest): Versions|CannotJudge
    {
        return match ($runner) {
            BuiltinRunner::Pest => PestReleases::versionsIn($manifest),
            BuiltinRunner::Infection => InfectionReleases::versionsIn($manifest),
            BuiltinRunner::PhpUnit => PhpUnitReleases::versionsIn($manifest),
            BuiltinRunner::PhpSpec => PhpSpecReleases::versionsIn($manifest),
            BuiltinRunner::Codeception => CodeceptionReleases::versionsIn($manifest),
        };
    }
}
