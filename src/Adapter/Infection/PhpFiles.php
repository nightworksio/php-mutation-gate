<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function is_array;
use function is_dir;
use function is_file;

use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function scandir;

/** The PHP files a path names: the file itself, or every PHP file under the directory, in name order. */
final readonly class PhpFiles
{
    /**
     * @return list<string> every PHP file these paths name, by its path on disk
     */
    public static function in(Project $project, Paths $paths): array
    {
        $found = [];

        foreach ($paths as $path) {
            $found = [...$found, ...self::under(DiskPath::of($project->absolute($path)))];
        }

        return $found;
    }

    /** @return list<string> every PHP file a path on disk names, or holds at any depth */
    public static function under(DiskPath $path): array
    {
        if (is_file($path->value())) {
            return Path::of($path->value())->isPhp() ? [$path->value()] : [];
        }

        $entries = is_dir($path->value()) ? scandir($path->value()) : [];
        $found = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            $inside = $entry === '.' || $entry === '..' ? [] : self::under($path->child($entry));
            $found = [...$found, ...$inside];
        }

        return $found;
    }
}
