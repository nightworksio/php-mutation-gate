<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_filter;
use function array_values;
use function count;
use function implode;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

use function sprintf;

/**
 * Where the config is (ADR-0002): the file `--config` names, or the one
 * `mutation-gate.*` in the project. Two of them is an error rather than a
 * precedence rule, and none is zero-config.
 */
final readonly class ConfigLocation
{
    /**
     * The file `--config` names: a path from the project, or an absolute one.
     * It takes the project as the command line spells it (owner: config).
     */
    public static function named(string $project, string $given): Path
    {
        return Path::of(Root::of($project)->at(Path::of($given))->value());
    }

    /**
     * The config file of the project, as the command line spells the project
     * (owner: config).
     *
     * @param string $given the file `--config` names, relative to the project; '' when it names none
     */
    public static function in(string $project, string $given): Path|Absent|CannotJudge
    {
        if ($given !== '') {
            return self::named($project, $given);
        }

        $present = array_values(array_filter(
            Format::fileNames(),
            static fn(string $name): bool => is_file(Root::of($project)->at(Path::of($name))->value()),
        ));

        return match (count($present)) {
            0 => Absent::setting(),
            1 => Path::of(Root::of($project)->at(Path::of($present[0]))->value()),
            default => CannotJudge::because(sprintf(
                'More than one config file is here: %s. Keep one, or name one with --config.',
                implode(', ', $present),
            )),
        };
    }
}
