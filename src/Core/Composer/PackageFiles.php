<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The files of the packages in a vendor directory, as a VendorPatch reads
 * and writes them.
 *
 * @internal
 */
interface PackageFiles
{
    /** The file's contents; or none, where no file is there. */
    public function read(string $path): string|NotGiven;

    /**
     * Every file under a directory, by its path under it.
     *
     * @return array<string, string> each file's contents
     */
    public function every(string $directory): array;

    /** Whether the file at a path can be written. */
    public function isWritable(string $path): bool;

    /** Whether the file now holds these contents. */
    public function written(string $path, string $contents): bool;
}
