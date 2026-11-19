<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function chmod;
use function copy;
use function fileperms;

use FilesystemIterator;

use function is_dir;
use function is_link;
use function mkdir;
use function readlink;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function rmdir;

use RuntimeException;
use SplFileInfo;

use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function symlink;
use function unlink;

/** A fixture's directory copied and removed as a whole, its links kept as links and its files' modes kept (see OwnFixtures). */
final readonly class FixtureCopy
{
    /** The original's own run files, which a copy starts without. */
    private const string LEFT_OUT = '.mutation-gate';

    /** A directory copied, its links kept as links and the original's run files left out. */
    public static function copied(string $from, string $into): void
    {
        self::made($into);

        foreach (is_dir($from) ? self::entries($from, childrenFirst: false) : [] as $entry) {
            $relative = substr($entry->getPathname(), strlen($from) + 1);

            if ($relative !== self::LEFT_OUT && ! str_starts_with($relative, sprintf('%s/', self::LEFT_OUT))) {
                self::placedAt($entry, sprintf('%s/%s', $into, $relative));
            }
        }
    }

    /** A directory and all it holds removed, links removed as links. */
    public static function removed(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }

        foreach (self::entries($directory, childrenFirst: true) as $entry) {
            $path = $entry->getPathname();
            $entry->isDir() && ! is_link($path) ? rmdir($path) : unlink($path);
        }

        rmdir($directory);
    }

    /** One entry of a directory copied to this path: a link as a link, a directory made, a file copied. */
    private static function placedAt(SplFileInfo $entry, string $target): void
    {
        $from = $entry->getPathname();

        match (true) {
            is_link($from) => self::linked((string) readlink($from), $target),
            $entry->isDir() => self::made($target),
            default => self::filed($from, $target),
        };
    }

    private static function linked(string $to, string $link): void
    {
        if (! symlink($to, $link)) {
            throw new RuntimeException(sprintf('The test cannot link %s.', $link));
        }
    }

    private static function filed(string $from, string $into): void
    {
        if (! copy($from, $into) || ! chmod($into, fileperms($from) & 0o777)) {
            throw new RuntimeException(sprintf('The test cannot copy %s.', $from));
        }
    }

    private static function made(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0o777, recursive: true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('The test cannot make %s.', $directory));
        }
    }

    /**
     * Every entry under a directory, each directory before what it holds, or after it.
     *
     * @return list<SplFileInfo>
     */
    private static function entries(string $directory, bool $childrenFirst): array
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            $childrenFirst ? RecursiveIteratorIterator::CHILD_FIRST : RecursiveIteratorIterator::SELF_FIRST,
        );
        $found = [];

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo) {
                $found[] = $entry;
            }
        }

        return $found;
    }
}
