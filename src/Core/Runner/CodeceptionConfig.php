<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_map;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * The names Codeception's config has in a directory, in the order the gate
 * reads them: Codeception reads both, the first over the second.
 */
enum CodeceptionConfig: string
{
    case Yml = 'codeception.yml';
    case DistYml = 'codeception.dist.yml';

    /** Where each name would be in a directory, in the gate's order. */
    public static function candidatesIn(Path $directory): Paths
    {
        return Paths::of(...array_map(
            static fn(self $config): Path => $directory->child(Path::of($config->value)),
            self::cases(),
        ));
    }
}
