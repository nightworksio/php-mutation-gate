<?php

declare(strict_types=1);

namespace Tests;

use function array_diff;
use function array_values;
use function clearstatcache;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function getmypid;

use Library\Money;

use function mkdir;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function rmdir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function touch;
use function unlink;

/** A project's own file operations, which pass under the gate's file:// wrapper as they do without it. */
final class FilesSpec extends TestCase
{
    #[Test]
    public function locksTruncatesTouchesAndListsFiles(): void
    {
        new Money()->count();
        $directory = sprintf('%s/files-spec-%d', sys_get_temp_dir(), getmypid());
        mkdir($directory);
        $file = sprintf('%s/notes.txt', $directory);
        $handle = fopen($file, 'c+b');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX));
        fwrite($handle, 'written');
        self::assertTrue(ftruncate($handle, 3));
        self::assertTrue(flock($handle, LOCK_UN));
        fclose($handle);
        self::assertTrue(touch($file, 1_000_000));
        clearstatcache();
        self::assertSame(1_000_000, filemtime($file));
        self::assertSame('wri', file_get_contents($file));
        self::assertSame(1, file_put_contents($file, '!', FILE_APPEND | LOCK_EX));
        self::assertSame(['notes.txt'], array_values(array_diff(scandir($directory) ?: [], ['.', '..'])));
        unlink($file);
        rmdir($directory);
        self::assertFalse(file_exists($directory));
    }
}
