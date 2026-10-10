<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function chmod;
use function copy;
use function dirname;
use function fclose;
use function file_put_contents;

use FilesystemIterator;

use function fopen;
use function ftruncate;
use function is_dir;
use function mb_strlen;
use function mb_substr;
use function mkdir;
use function random_bytes;
use function readlink;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function register_shutdown_function;
use function rmdir;
use function sodium_bin2hex;

use SplFileInfo;

use function sprintf;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

/**
 * Directories a test writes into, each new and empty, removed by `sweep()`, or,
 * for what a shutdown function writes, once the process has ended.
 */
final class Scratch
{
    /** @var list<string> */
    private static array $made = [];

    /** A new, empty directory, by its absolute path. */
    public static function directory(): string
    {
        $root = self::fresh();
        self::$made[] = $root;

        return $root;
    }

    /**
     * A new, empty directory that no sweep removes, for a file a shutdown
     * function writes. It is removed once every shutdown function registered
     * before the process began to end has run.
     */
    public static function untilExit(): string
    {
        $root = self::fresh();

        register_shutdown_function(static function () use ($root): void {
            register_shutdown_function(static function () use ($root): void {
                self::remove($root);
            });
        });

        return $root;
    }

    /** Write a file under a scratch directory, creating the directories it needs. */
    public static function write(string $root, string $path, string $contents): void
    {
        $file = sprintf('%s/%s', $root, $path);

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), recursive: true);
        }

        file_put_contents($file, $contents);
    }

    /**
     * Make a file under a scratch directory of this many zero bytes, never holding them, with the directories it needs.
     *
     * @param int<0, max> $bytes
     */
    public static function sized(string $root, string $path, int $bytes): void
    {
        self::write($root, $path, '');
        $handle = fopen(sprintf('%s/%s', $root, $path), 'r+b');

        if ($handle !== false && ftruncate($handle, $bytes)) {
            fclose($handle);
        }
    }

    /** A new directory holding a copy of one under the repository's tests. */
    public static function copy(string $fixture): string
    {
        $root = self::directory();
        self::copied(Tree::at($fixture), $root);

        return $root;
    }

    /** Remove every directory made since the last sweep. */
    public static function sweep(): void
    {
        foreach (self::$made as $root) {
            self::remove($root);
        }

        self::$made = [];
    }

    private static function remove(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        foreach (self::entries($root, RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            self::opened($entry);
        }

        foreach (self::entries($root, RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            self::removed($entry);
        }

        rmdir($root);
    }

    /** Copies a directory's contents into another, keeping each file's mode and each link as a link. */
    private static function copied(string $from, string $into): void
    {
        foreach (self::entries($from, RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $target = sprintf('%s%s', $into, mb_substr($entry->getPathname(), mb_strlen($from)));

            if ($entry->isLink()) {
                symlink((string) readlink($entry->getPathname()), $target);

                continue;
            }

            if ($entry->isDir()) {
                mkdir($target);

                continue;
            }

            copy($entry->getPathname(), $target);
            chmod($target, $entry->getPerms() & 0777);
        }
    }

    /**
     * Every entry under a directory, in this order.
     *
     * @param  RecursiveIteratorIterator::SELF_FIRST|RecursiveIteratorIterator::CHILD_FIRST $mode
     * @return list<SplFileInfo>
     */
    private static function entries(string $root, int $mode): array
    {
        $entries = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), $mode) as $entry) {
            if ($entry instanceof SplFileInfo) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** Lets a directory a test made read-only be emptied. */
    private static function opened(SplFileInfo $entry): void
    {
        if ($entry->isDir() && ! $entry->isLink()) {
            chmod($entry->getPathname(), 0700);
        }
    }

    private static function removed(SplFileInfo $entry): void
    {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    private static function fresh(): string
    {
        $root = sprintf('%s/mutation-gate-scratch-%s', sys_get_temp_dir(), sodium_bin2hex(random_bytes(8)));
        mkdir($root);

        return $root;
    }
}
