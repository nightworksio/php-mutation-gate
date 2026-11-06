<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_string;
use function is_writable;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Composer\PackageFiles;
use NightWorksIO\MutationGate\Core\NotGiven;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function sprintf;

/**
 * The files of the packages in a vendor directory on disk.
 *
 * @internal
 */
final readonly class PackageDirectory implements PackageFiles
{
    public function read(string $path): string|NotGiven
    {
        return is_file($path) ? sprintf('%s', file_get_contents($path)) : NotGiven::value();
    }

    public function every(string $directory): array
    {
        $every = [];
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($entries as $pathname => $entry) {
            $every += is_string($pathname)
                ? [mb_substr($pathname, mb_strlen($directory)) => sprintf('%s', file_get_contents($pathname))]
                : [];
        }

        return $every;
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    public function written(string $path, string $contents): bool
    {
        return file_put_contents($path, $contents) !== false;
    }
}
