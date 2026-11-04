<?php

declare(strict_types=1);

namespace Tests;

use function bin2hex;

use Library\Linked;

use function mkdir;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function random_bytes;
use function rmdir;
use function sprintf;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

// Infection's include-interceptor, as it ships, stats a path it cannot read
// as missing, so while it serves a mutant a dangling link is no link, and
// this test fails whatever the mutant changes. Its directory is named by
// random bytes, as two of Infection's runs side by side could share
// uniqid()'s microsecond and remove each other's link.
final class LinkedSpec extends TestCase
{
    #[Test]
    public function tellsADanglingLinkForALink(): void
    {
        $directory = sprintf('%s/linked-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        mkdir($directory);
        symlink(sprintf('%s/gone', $directory), sprintf('%s/dangling', $directory));

        try {
            self::assertTrue(new Linked()->isLink(sprintf('%s/dangling', $directory)));
        } finally {
            unlink(sprintf('%s/dangling', $directory));
            rmdir($directory);
        }
    }
}
