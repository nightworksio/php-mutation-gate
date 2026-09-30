<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function dirname;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function register_shutdown_function;
use function sodium_bin2hex;
use function sprintf;
use function sys_get_temp_dir;

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

    /** A new directory holding a copy of one under the repository's tests. */
    public static function copy(string $fixture): string
    {
        $root = self::directory();
        exec(sprintf('cp -R %s/. %s', escapeshellarg(Tree::at($fixture)), escapeshellarg($root)));

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
        exec(sprintf('rm -rf %s', escapeshellarg($root)));
    }

    private static function fresh(): string
    {
        $root = sprintf('%s/mutation-gate-scratch-%s', sys_get_temp_dir(), sodium_bin2hex(random_bytes(8)));
        mkdir($root);

        return $root;
    }
}
