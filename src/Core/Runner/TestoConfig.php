<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/** The name Testo's config has in a directory, which Testo reads where no `--config` names another. */
enum TestoConfig: string
{
    case Php = 'testo.php';

    /** Where the config would be in a directory. */
    public static function candidatesIn(Path $directory): Paths
    {
        return Paths::of($directory->child(Path::of(self::Php->value)));
    }
}
