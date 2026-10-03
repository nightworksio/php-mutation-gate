<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitIni;

use function sprintf;

/**
 * The project's PHPUnit config, which Infection runs each mutant with: the
 * first of its names PHPUnit finds in `phpUnit.configDir`, or in the root.
 */
final readonly class ProjectPhpUnit
{
    /** The config's file on disk; none where the project has none. */
    public static function file(Project $project, OwnConfig $config): string|NotGiven
    {
        foreach ($config->phpUnitConfigs($project) as $candidate) {
            if (is_file($project->absolute($candidate))) {
                return $project->absolute($candidate);
            }
        }

        return NotGiven::value();
    }

    /**
     * Where the config has PHP print errors: the last `display_errors` its
     * `<php>` sets, which PHPUnit sets as it starts, over the cap's ini
     * (ADR-0004, decision 9); none where it sets none or is not XML.
     */
    public static function display(Project $project, OwnConfig $config): ErrorDisplay|NotGiven
    {
        $file = self::file($project, $config);

        return $file instanceof NotGiven
            ? $file
            : PhpUnitIni::displayIn(sprintf('%s', file_get_contents($file)));
    }
}
