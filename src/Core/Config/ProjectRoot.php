<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Path;

/** The origin of a preset, of the command line and of the effective config: the project itself. */
final readonly class ProjectRoot implements Origin
{
    public static function origin(): self
    {
        return new self();
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
