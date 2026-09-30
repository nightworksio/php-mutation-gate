<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * Where a layer of config is written, which is where its paths are named
 * from (ADR-0002): a config file names them from its own directory, and a
 * preset and the command line from the project. Every path a layer names is
 * inside the project, but for a file the operator names on the command line
 * by its absolute path.
 */
interface PathOrigin
{
    /** Whether this layer may name a file outside the project by its absolute path: the command line's own. */
    public function reachesOutside(): bool;

    /** A path as this layer writes it, `../src` from `ci/`, as the path from the project it names. */
    public function path(Path $written): Path;

    /** A path from the project, as this layer would write it. */
    public function written(Path $path): string;
}
