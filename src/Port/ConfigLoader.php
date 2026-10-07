<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
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

    /**
     * The files the config file reads beside itself, found without running
     * it, each named from the project's root; none where its format reads
     * no other file; or why it reads one that cannot be named, which makes
     * every change decide how the gate runs (ADR-0005, decision 4).
     */
    public function reads(ConfigFile $file): ConfigReads;
}
