<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use function array_filter;

use const ARRAY_FILTER_USE_BOTH;

use function count;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;

/**
 * The source of packages in a vendor directory, as VendorPatches read and
 * write it through its files.
 *
 * @internal
 */
final readonly class PackageSource
{
    private function __construct(private PackageFiles $files)
    {
    }

    public static function of(PackageFiles $files): self
    {
        return new self($files);
    }

    /**
     * Patch packages in a vendor directory, and say what was done, one
     * sentence for each. Every patch is checked before any file is written,
     * so a package whose lines have moved leaves every package as it was.
     */
    public function applyIn(string $vendor, VendorPatch ...$patches): string|CannotJudge
    {
        $changes = $this->changesIn($vendor, ...$patches);

        if ($changes instanceof CannotJudge) {
            return $changes;
        }

        $said = [];

        foreach ($patches as $at => $patch) {
            $written = $this->written($vendor, $patch, $changes[$at]);

            if ($written instanceof CannotJudge) {
                return $written;
            }

            $said[] = $written;
        }

        return implode(' ', $said);
    }

    /** Whether each package in a vendor directory carries every hunk of its patch, and none another version wrote. */
    public function isAppliedIn(string $vendor, VendorPatch ...$patches): bool
    {
        foreach ($patches as $patch) {
            $sources = $this->read($patch, $vendor);

            if ($sources instanceof CannotJudge || ! $patch->isAppliedTo($sources, $this->every($patch, $vendor))) {
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
    private function changesIn(string $vendor, VendorPatch ...$patches): array|CannotJudge
    {
        $changes = [];

        foreach ($patches as $at => $patch) {
            $sources = $this->read($patch, $vendor);
            $changed = $sources instanceof CannotJudge
                ? $sources
                : $patch->patched($vendor, $sources, $this->every($patch, $vendor), $this->files->isWritable(...));

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
     * @param array<string, string> $changed each changed file's source, by its path under the source directory
     */
    private function written(string $vendor, VendorPatch $patch, array $changed): string|CannotJudge
    {
        $unwritten = array_filter(
            $changed,
            fn(string $source, string $file): bool => ! $this->files->written($patch->path($vendor, $file), $source),
            ARRAY_FILTER_USE_BOTH,
        );

        return $unwritten === [] ? $patch->done(count($changed)) : $patch->unwritten($vendor);
    }

    /**
     * Each file a hunk changes, by its path under the source directory; or
     * why one cannot be read.
     *
     * @return array<string, string>|CannotJudge
     */
    private function read(VendorPatch $patch, string $vendor): array|CannotJudge
    {
        $sources = [];

        foreach ($patch->files() as $file) {
            $path = $patch->path($vendor, $file);
            $source = $this->files->read($path);

            if (! is_string($source)) {
                return $patch->unreadable($path);
            }

            $sources[$file] = $source;
        }

        return $sources;
    }

    /**
     * Every file of the package's source, by its path under the source directory.
     *
     * @return array<string, string>
     */
    private function every(VendorPatch $patch, string $vendor): array
    {
        return $this->files->every($patch->path($vendor));
    }
}
