<?php

declare(strict_types=1);

use Library\Linked;

it('tells a link for a link, from the file it is declared in, reading a file through PHP\'s own wrapper', function (): void {
    $directory = sprintf('%s/linked-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
    mkdir($directory);
    touch(sprintf('%s/there', $directory));
    symlink(sprintf('%s/there', $directory), sprintf('%s/link', $directory));
    $handle = fopen(sprintf('%s/there', $directory), 'rb');
    $wrapper = $handle === false ? '' : stream_get_meta_data($handle)['wrapper_type'];
    $declared = realpath(sprintf('%s/src/Linked.php', dirname(__DIR__)));

    try {
        expect(new Linked()->isLink(sprintf('%s/link', $directory)))->toBeTrue()
            ->and($wrapper)->toBe('plainfile')
            ->and(new Linked()->file())->toBe($declared)
            ->and(new Linked()->directory())->toBe(dirname((string) $declared));
    } finally {
        if ($handle !== false) {
            fclose($handle);
        }

        unlink(sprintf('%s/link', $directory));
        unlink(sprintf('%s/there', $directory));
        rmdir($directory);
    }
});
