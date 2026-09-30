<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

/** The directory the gate keeps its own files under in a project, wherever nothing else is configured. */
final readonly class Workspace
{
    private const string ROOT = '.mutation-gate';

    /** The directory itself. */
    public static function root(): Path
    {
        return Path::of(self::ROOT);
    }
}
