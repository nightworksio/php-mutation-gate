<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('removes nothing where the directory is not there', function (): void {
    $root = (string) realpath(Scratch::directory());
    $missing = sprintf('%s/php/1', $root);

    new CapDirectory()->removed(DiskPath::of($missing));

    expect(is_dir($missing))->toBeFalse()
        ->and(glob(sprintf('%s/*', $root)))->toBe([]);
});

it('removes nothing through a link in the directory\'s place, and leaves what its target holds', function (): void {
    $root = (string) realpath(Scratch::directory());
    $target = sprintf('%s/elsewhere', $root);
    mkdir($target);
    file_put_contents(sprintf('%s/kept.ini', $target), 'memory_limit=1G');
    $linked = sprintf('%s/php', $root);
    symlink($target, $linked);

    new CapDirectory()->removed(DiskPath::of($linked));

    expect(is_link($linked))->toBeTrue()
        ->and(glob(sprintf('%s/*', $target)))->toBe([sprintf('%s/kept.ini', $target)]);
});
