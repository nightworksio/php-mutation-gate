<?php

declare(strict_types=1);

namespace Tests;

use const E_USER_WARNING;

use function fclose;
use function feof;
use function fgets;
use function file_exists;
use function file_put_contents;
use function fopen;
use function getmypid;
use function ini_set;
use function is_file;
use function is_link;

use Library\Money;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileObject;

use function sprintf;
use function stream_wrapper_restore;
use function stream_wrapper_unregister;
use function strlen;
use function symlink;
use function sys_get_temp_dir;
use function touch;
use function trigger_error;
use function unlink;

/**
 * Tests that each do what a project's tests do and the gate must not read as
 * a verdict: counting a file's lines, touching and stating files, skipping,
 * warning, replacing PHP's own `file://` wrapper, and running out of memory.
 */
final class QuirksSpec extends TestCase
{
    #[Test]
    public function readsEveryLineToTheEnd(): void
    {
        new Money()->count();
        $file = sprintf('%s/quirks-lines-%d.txt', sys_get_temp_dir(), getmypid());
        file_put_contents($file, "a\nb\n");
        $handle = fopen($file, 'rb');
        $lines = [];

        while (! feof($handle)) {
            $lines[] = fgets($handle);
        }

        fclose($handle);
        $object = [];

        foreach (new SplFileObject($file) as $line) {
            $object[] = $line;
        }

        unlink($file);

        self::assertSame([["a\n", "b\n", false], ["a\n", "b\n", '']], [$lines, $object]);
    }

    #[Test]
    public function touchesAFileAndStatesADanglingLink(): void
    {
        new Money()->count();
        $file = sprintf('%s/quirks-touched-%d.txt', sys_get_temp_dir(), getmypid());
        $link = sprintf('%s/quirks-dangling-%d', sys_get_temp_dir(), getmypid());
        touch($file);
        symlink(sprintf('%s.gone', $file), $link);
        $stated = [file_exists($link), is_file($link), is_link($link)];
        unlink($link);
        unlink($file);

        self::assertSame([false, false, true], $stated);
    }

    #[Test]
    public function skipsItself(): void
    {
        new Money()->count();

        self::markTestSkipped('a project skips a test');
    }

    #[Test]
    #[RequiresPhpExtension('an_extension_no_php_has')]
    public function needsAnExtensionNoPhpHas(): void
    {
        new Money()->count();

        self::assertTrue(true);
    }

    #[Test]
    public function warns(): void
    {
        new Money()->count();
        trigger_error('a project warns', E_USER_WARNING);

        self::assertTrue(true);
    }

    #[Test]
    public function putsBackPhpsOwnFileWrapperFirst(): void
    {
        stream_wrapper_unregister('file');
        stream_wrapper_restore('file');

        self::assertSame(5, new Money()->add(2, 3));
    }

    #[Test]
    public function padsWithinMemory(): void
    {
        ini_set('memory_limit', '64M');

        self::assertSame(1, strlen(new Money()->padded(2 ** 40)));
    }
}
