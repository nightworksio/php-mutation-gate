<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\File\Path;

/** One config format: PHP, JSON, YAML, NEON. */
interface ConfigLoader
{
    /** The config file read into the untyped tree every format shares, before it is validated. */
    public function load(Path $file): Document|CannotJudge;
}
