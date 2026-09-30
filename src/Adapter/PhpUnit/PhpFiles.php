<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_any;
use function array_filter;
use function array_values;
use function is_dir;
use function is_file;
use function is_string;
use function ksort;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function sort;

/** The PHP files some paths of a project name, each file itself or every PHP file a directory holds, in byte order. */
final readonly class PhpFiles
{
    /** @return list<Path> each as a path of the project, but those inside a path left out */
    public static function in(Project $project, Paths $paths, Paths $leftOut): array
    {
        $found = [];

        foreach ($paths as $path) {
            foreach (self::onDisk($project->absolute($path)) as $file) {
                $found[$file] = $project->relative($file);
            }
        }

        ksort($found);

        $left = [...$leftOut];

        return array_values(array_filter(
            $found,
            static fn(Path $file): bool => ! array_any($left, static fn(Path $out): bool => $file->within($out)),
        ));
    }

    /** @return list<string> the path itself where it is a PHP file, or every PHP file a directory holds */
    private static function onDisk(string $path): array
    {
        if (! is_dir($path)) {
            return is_file($path) && Path::of($path)->isPhp() ? [$path] : [];
        }

        return self::under($path);
    }

    /** @return list<string> every PHP file a directory holds, at any depth */
    private static function under(string $path): array
    {
        $files = [];
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($entries as $pathname => $entry) {
            if (is_string($pathname) && is_file($pathname) && Path::of($pathname)->isPhp()) {
                $files[] = $pathname;
            }
        }

        sort($files);

        return $files;
    }
}
