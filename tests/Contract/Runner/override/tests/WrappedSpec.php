<?php

declare(strict_types=1);

use Library\Linked;

// While pest-plugin-mutate's override serves any file, every file opens
// through its user-space wrapper, so this test fails whatever the file served
// holds, as any test does that reads how PHP opened a file.
it('tells a link for a link, reading a file through PHP\'s own wrapper', function (): void {
    $directory = sprintf('%s/linked-%s', sys_get_temp_dir(), uniqid());
    mkdir($directory);
    touch(sprintf('%s/there', $directory));
    symlink(sprintf('%s/there', $directory), sprintf('%s/link', $directory));
    $handle = fopen(sprintf('%s/there', $directory), 'rb');
    $wrapper = $handle === false ? '' : stream_get_meta_data($handle)['wrapper_type'];

    try {
        expect(new Linked()->isLink(sprintf('%s/link', $directory)))->toBeTrue()
            ->and($wrapper)->toBe('plainfile');
    } finally {
        if ($handle !== false) {
            fclose($handle);
        }

        unlink(sprintf('%s/link', $directory));
        unlink(sprintf('%s/there', $directory));
        rmdir($directory);
    }
});
