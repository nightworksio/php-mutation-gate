<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_map;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * The names PhpSpec's config has, in the order PhpSpec looks for them in a
 * directory: it reads the first it finds.
 */
enum PhpSpecConfig: string
{
    case Yml = 'phpspec.yml';
    case HiddenYml = '.phpspec.yml';
    case YmlDist = 'phpspec.yml.dist';
    case Yaml = 'phpspec.yaml';
    case HiddenYaml = '.phpspec.yaml';
    case YamlDist = 'phpspec.yaml.dist';

    /** Where each name would be in a directory, in PhpSpec's order. */
    public static function candidatesIn(Path $directory): Paths
    {
        return Paths::of(...array_map(
            static fn(self $config): Path => $directory->child(Path::of($config->value)),
            self::cases(),
        ));
    }
}
