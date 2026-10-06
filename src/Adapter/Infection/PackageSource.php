<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function count;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_string;
use function is_writable;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\VendorPatch;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function sprintf;

/** The source of Infection in a vendor directory, as a VendorPatch reads and writes it. */
final readonly class PackageSource
{
    /** Patch the package in a vendor directory, and say what was done. */
    public static function applyIn(VendorPatch $patch, string $vendor): string|CannotJudge
    {
        $sources = self::read($patch, $vendor);
        $changed = $sources instanceof CannotJudge
            ? $sources
            : $patch->patched($vendor, $sources, self::every($patch, $vendor), is_writable(...));

        if ($changed instanceof CannotJudge) {
            return $changed;
        }

        $unwritten = 0;

        foreach ($changed as $file => $source) {
            $unwritten += file_put_contents($patch->path($vendor, $file), $source) === false ? 1 : 0;
        }

        return $unwritten === 0 ? $patch->done(count($changed)) : $patch->unwritten($vendor);
    }

    /** Whether the package in a vendor directory carries every hunk, and no hunk another version wrote. */
    public static function isAppliedIn(VendorPatch $patch, string $vendor): bool
    {
        $sources = self::read($patch, $vendor);

        return ! $sources instanceof CannotJudge && $patch->isAppliedTo($sources, self::every($patch, $vendor));
    }

    /**
     * Each file a hunk changes, by its path under the source directory; or
     * why one cannot be read.
     *
     * @return array<string, string>|CannotJudge
     */
    private static function read(VendorPatch $patch, string $vendor): array|CannotJudge
    {
        $sources = [];

        foreach ($patch->files() as $file) {
            $path = $patch->path($vendor, $file);

            if (! is_file($path)) {
                return $patch->unreadable($path);
            }

            $sources[$file] = sprintf('%s', file_get_contents($path));
        }

        return $sources;
    }

    /**
     * Every file of the package's source, by its path under the source
     * directory, once the files a hunk changes were read from it.
     *
     * @return array<string, string>
     */
    private static function every(VendorPatch $patch, string $vendor): array
    {
        $root = $patch->path($vendor);
        $every = [];
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($entries as $pathname => $entry) {
            $every += is_string($pathname)
                ? [mb_substr($pathname, mb_strlen($root)) => (string) file_get_contents($pathname)]
                : [];
        }

        return $every;
    }
}
