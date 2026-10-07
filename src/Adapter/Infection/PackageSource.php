<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use Closure;

use function count;
use function file_get_contents;
use function file_put_contents;
use function implode;
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

/** The source of Infection's packages in a vendor directory, as a VendorPatch reads and writes it. */
final readonly class PackageSource
{
    /**
     * Patch packages in a vendor directory, and say what was done, one
     * sentence for each. Every patch is checked before any file is written,
     * so a package whose lines have moved leaves every package as it was.
     */
    public static function applyIn(string $vendor, VendorPatch ...$patches): string|CannotJudge
    {
        return self::applyWith(file_put_contents(...), $vendor, ...$patches);
    }

    /**
     * The same, each changed file written by this, which answers false where
     * it could not write it.
     *
     * @param Closure(string, string): (int|false) $write
     */
    public static function applyWith(Closure $write, string $vendor, VendorPatch ...$patches): string|CannotJudge
    {
        $changes = self::changesIn($vendor, ...$patches);

        if ($changes instanceof CannotJudge) {
            return $changes;
        }

        $said = [];

        foreach ($patches as $at => $patch) {
            $written = self::written($write, $vendor, $patch, $changes[$at]);

            if ($written instanceof CannotJudge) {
                return $written;
            }

            $said[] = $written;
        }

        return implode(' ', $said);
    }

    /** Whether each package in a vendor directory carries every hunk of its patch, and none another version wrote. */
    public static function isAppliedIn(string $vendor, VendorPatch ...$patches): bool
    {
        foreach ($patches as $patch) {
            $sources = self::read($patch, $vendor);

            if ($sources instanceof CannotJudge || ! $patch->isAppliedTo($sources, self::every($patch, $vendor))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Each patch's files with the hunks they lack applied, by the patch's
     * place in the list; or why a patch cannot be applied.
     *
     * @return array<array<string, string>>|CannotJudge
     */
    private static function changesIn(string $vendor, VendorPatch ...$patches): array|CannotJudge
    {
        $changes = [];

        foreach ($patches as $at => $patch) {
            $sources = self::read($patch, $vendor);
            $changed = $sources instanceof CannotJudge
                ? $sources
                : $patch->patched($vendor, $sources, self::every($patch, $vendor), is_writable(...));

            if ($changed instanceof CannotJudge) {
                return $changed;
            }

            $changes[$at] = $changed;
        }

        return $changes;
    }

    /**
     * What a patch did once its changed files are written; or why they could not all be.
     *
     * @param Closure(string, string): (int|false) $write
     * @param array<string, string>               $changed each changed file's source, by its path under the source
     *                                                     directory
     */
    private static function written(
        Closure $write,
        string $vendor,
        VendorPatch $patch,
        array $changed,
    ): string|CannotJudge {
        $unwritten = 0;

        foreach ($changed as $file => $source) {
            $unwritten += $write($patch->path($vendor, $file), $source) === false ? 1 : 0;
        }

        return $unwritten === 0 ? $patch->done(count($changed)) : $patch->unwritten($vendor);
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
