<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function dirname;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

/** Directories a test writes into, each new and empty, removed by `sweep()`. */
final class Scratch
{
    /** @var list<string> */
    private static array $made = [];

    /** A new, empty directory, by its absolute path. */
    public static function directory(): string
    {
        $root = sprintf('%s/mutation-gate-scratch-%s', sys_get_temp_dir(), uniqid());
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

    /** Remove every directory made since the last sweep. */
    public static function sweep(): void
    {
        foreach (self::$made as $root) {
            exec(sprintf('rm -rf %s', escapeshellarg($root)));
        }

        self::$made = [];
    }
}
