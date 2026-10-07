<?php

declare(strict_types=1);

use Library\Linked;

// A link to a file that is there reads alike through pest-plugin-mutate's
// override and without it. Each run makes its own directory, named by random
// bytes rather than uniqid(), whose microsecond two processes can share: Pest
// runs a file's mutants side by side, and two runs in one directory remove
// each other's link, which reads as a kill that a control run on its own then
// vouches for.
it('tells a link for a link', function (): void {
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
