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
use function sodium_bin2hex;
use function sprintf;
use function sys_get_temp_dir;

/** Directories a test writes into, each new and empty, removed by `sweep()`. */
final class Scratch
{
    /** @var list<string> */
    private static array $made = [];

    /** A new, empty directory, by its absolute path. */
    public static function directory(): string
    {
        $root = sprintf('%s/mutation-gate-scratch-%s', sys_get_temp_dir(), sodium_bin2hex(random_bytes(8)));
        mkdir($root);
        self::$made[] = $root;

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
            exec(sprintf('rm -rf %s', escapeshellarg($root)));
        }

        self::$made = [];
    }
}
