<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function dirname;
use function is_dir;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function sort;

use SplFileInfo;

use function sprintf;
use function str_ends_with;
use function str_replace;

/** The repository, as its files. */
final readonly class Tree
{
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function at(string $path): string
    {
        return sprintf('%s/%s', self::root(), $path);
    }

    /** A path under the root, relative to it. */
    public static function relative(string $path): string
    {
        return str_replace(sprintf('%s/', self::root()), '', $path);
    }

    /**
     * Every file under a directory of the repository ending in a suffix, as
     * paths relative to the root, in byte order. A directory that is not there
     * holds nothing.
     *
     * @return list<string>
     */
    public static function filesUnder(string $directory, string $suffix = '.php'): array
    {
        if (! is_dir(self::at($directory))) {
            return [];
        }

        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::at($directory), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getPathname(), $suffix)) {
                $found[] = self::relative($file->getPathname());
            }
        }

        sort($found);

        return $found;
    }
}
