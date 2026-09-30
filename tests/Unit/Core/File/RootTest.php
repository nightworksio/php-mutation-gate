<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

it('keeps a directory as it is spelt, without a trailing separator', function (string $spelt, string $kept): void {
    expect(Root::of($spelt)->value())->toBe($kept);
})->with([
    'absolute' => ['/srv/app/', '/srv/app'],
    'relative' => ['app', 'app'],
    'the filesystem root' => ['/', '/'],
    'nothing, the working directory' => ['', '.'],
]);

it('is the working directory where nothing else is named', function (): void {
    expect(Root::here())->toEqual(Root::of('.'));
});

it('says where a path under it is on disk', function (string $root, string $path, string $onDisk): void {
    expect(Root::of($root)->at(Path::of($path)))->toEqual(DiskPath::of($onDisk));
})->with([
    'a file' => ['/srv/app', 'src/Money.php', '/srv/app/src/Money.php'],
    'the root itself' => ['/srv/app', '.', '/srv/app'],
    'an absolute path, where it says' => ['/srv/app', '/opt/vendor', '/opt/vendor'],
    'under the filesystem root' => ['/', 'etc/hosts', '/etc/hosts'],
    'under the working directory' => ['.', 'src', './src'],
]);

it('spells a file on disk as a path under it', function (string $root, string $file, string $path): void {
    expect(Root::of($root)->relative($file))->toEqual(Path::of($path));
})->with([
    'inside' => ['/srv/app', '/srv/app/src/Money.php', 'src/Money.php'],
    'the root itself' => ['/srv/app', '/srv/app', '.'],
    'outside, as it is' => ['/srv/app', '/srv/application/Money.php', '/srv/application/Money.php'],
    'under the filesystem root' => ['/', '/etc/hosts', 'etc/hosts'],
]);

it('is itself a path on disk', function (): void {
    expect(Root::of('/srv/app')->path())->toEqual(DiskPath::of('/srv/app'));
});
