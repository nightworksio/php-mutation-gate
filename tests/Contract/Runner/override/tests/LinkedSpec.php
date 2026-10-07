<?php

declare(strict_types=1);

use Library\Linked;

// A link to a file that is there reads alike through pest-plugin-mutate's
// override and without it.
it('tells a link for a link', function (): void {
    $directory = sprintf('%s/linked-%s', sys_get_temp_dir(), uniqid());
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
