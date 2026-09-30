<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;

/** One config format: PHP, JSON, YAML, NEON. */
interface ConfigLoader
{
    /**
     * The config file read into its layer of config, its paths named from the file's own directory; every problem
     * in it at once, each at its path; or why it could not be read.
     */
    public function load(ConfigFile $file): Layer|Invalid|CannotJudge;
}
