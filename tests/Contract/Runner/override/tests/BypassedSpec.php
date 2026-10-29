<?php

declare(strict_types=1);

use Library\Linked;

require_once sprintf('%s/src/Linked.php', dirname(__DIR__));

it('tells a link for a link, from the class file it requires by its path', function (): void {
    $directory = sprintf('%s/linked-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
    mkdir($directory);
    touch(sprintf('%s/there', $directory));
    symlink(sprintf('%s/there', $directory), sprintf('%s/link', $directory));

    try {
        expect(new Linked()->isLink(sprintf('%s/link', $directory)))->toBeTrue();
    } finally {
        unlink(sprintf('%s/link', $directory));
        unlink(sprintf('%s/there', $directory));
        rmdir($directory);
    }
});
