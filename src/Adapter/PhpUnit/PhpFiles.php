<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_values;
use function is_dir;
use function is_file;
use function is_string;
use function ksort;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** The PHP files some paths of a project name, each file itself or every PHP file a directory holds, in byte order. */
final readonly class PhpFiles
{
    /** @return list<Path> each as a path of the project */
    public static function in(Installation $installation, Paths $paths): array
    {
        $found = [];

        foreach ($paths as $path) {
            foreach (self::onDisk($installation->absolute($path)) as $file) {
                $found[$file] = $installation->relative($file);
            }
        }

        ksort($found);

        return array_values($found);
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

        return $files;
    }
}
