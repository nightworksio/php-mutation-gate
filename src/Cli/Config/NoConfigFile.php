<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

/**
 * Where a config file would be, none: zero-config reads the project instead,
 * and `init` may write one there.
 */
final readonly class NoConfigFile
{
    private function __construct()
    {
    }

    public static function there(): self
    {
        return new self();
    }
}
