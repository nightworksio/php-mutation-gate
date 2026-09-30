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
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;
use function str_starts_with;

/**
 * Where the config is (ADR-0002): the file `--config` names, or the one
 * `mutation-gate.*` in the project. Two of them is an error rather than a
 * precedence rule, and none is zero-config.
 */
final readonly class ConfigFile
{
    /** The names the gate looks for, one per format. */
    public const array NAMES = [
        'mutation-gate.php',
        'mutation-gate.json',
        'mutation-gate.yaml',
        'mutation-gate.yml',
        'mutation-gate.neon',
    ];

    /** The file `--config` names: a path from the project, or an absolute one. */
    public static function named(string $project, string $given): Path
    {
        return Path::of(str_starts_with($given, '/') ? $given : sprintf('%s/%s', $project, $given));
    }

    /** @param string $given the file `--config` names, relative to the project; '' when it names none */
    public static function in(string $project, string $given): Path|Absent|CannotJudge
    {
        if ($given !== '') {
            return self::named($project, $given);
        }

        $present = array_values(array_filter(
            self::NAMES,
            static fn(string $name): bool => is_file(sprintf('%s/%s', $project, $name)),
        ));

        return match (count($present)) {
            0 => Absent::setting(),
            1 => Path::of(sprintf('%s/%s', $project, $present[0])),
            default => CannotJudge::because(sprintf(
                'More than one config file is here: %s. Keep one, or name one with --config.',
                implode(', ', $present),
            )),
        };
    }
}
