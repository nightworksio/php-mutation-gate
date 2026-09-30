<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Path;

/** The origin of a preset, of the command line and of the effective config: the project itself. */
final readonly class ProjectRoot implements PathOrigin
{
    private function __construct(private bool $outside)
    {
    }

    /** The origin of a preset and of the effective config, which name paths inside the project only. */
    public static function origin(): self
    {
        return new self(outside: false);
    }

    /** The origin of the command line, whose operator may also name a file outside the project by its absolute path. */
    public static function commandLine(): self
    {
        return new self(outside: true);
    }

    public function reachesOutside(): bool
    {
        return $this->outside;
    }

    public function path(Path $written): Path
    {
        return ConfigPath::of($written->value(), '')->path();
    }

    public function written(Path $path): string
    {
        return $path->value();
    }
}
