<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * Where a layer of config is written, which is where its paths are named
 * from (ADR-0002): a config file names them from its own directory, and a
 * preset and the command line from the project.
 */
interface Origin
{
    /** A path as this layer writes it, `../src` from `ci/`, as the path from the project it names. */
    public function path(Path $written): Path;

    /** A path from the project, as this layer would write it. */
    public function written(Path $path): string;
}
