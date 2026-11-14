<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function file_exists;

use FilesystemIterator;

use function is_link;
use function mkdir;

use NightWorksIO\MutationGate\Core\File\FileMode;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function rmdir;

use SplFileInfo;

use function sprintf;
use function sys_get_temp_dir;
use function unlink;

/**
 * The directory a run's split streams plan and record in (ADR-0117): made
 * fresh in the system's temporary directory under a name nobody can guess,
 * readable by this user alone, and never one that was already there or a
 * link, which another user could have planted; removed with everything in it
 * once the streams are judged.
 *
 * @internal
 */
final readonly class StreamDirectory
{
    /** The directory's name in the system's temporary one. */
    private const string NAME = '%s/mutation-gate-split-%s';

    /** How many random bytes name it. */
    private const int RANDOM = 16;

    /**
     * A fresh directory of this user's own.
     *
     * @throws UnmadeDirectory
     */
    public static function made(): string
    {
        return self::at(sprintf(self::NAME, sys_get_temp_dir(), Unguessable::text(self::RANDOM)));
    }

    /**
     * This path made a directory of this user's own; refused where anything,
     * a link too, is already there.
     *
     * @throws UnmadeDirectory
     */
    public static function at(string $directory): string
    {
        if (is_link($directory) || file_exists($directory) || ! mkdir($directory, FileMode::OWNER_ONLY)) {
            throw UnmadeDirectory::at($directory);
        }

        return $directory;
    }

    /** The directory removed, with everything in it. */
    public static function removed(string $directory): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $path = $entry instanceof SplFileInfo ? $entry->getPathname() : '';
            $removed = $entry instanceof SplFileInfo && $entry->isDir() && ! $entry->isLink()
                ? rmdir($path)
                : unlink($path);
        }

        rmdir($directory);
    }
}
