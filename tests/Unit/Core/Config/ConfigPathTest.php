<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\ConfigPath;
use NightWorksIO\MutationGate\Core\File\Path;

it('names a path from the project as a file in a directory of it writes it', function (
    string $path,
    string $directory,
    string $written,
): void {
    expect(ConfigPath::from(Path::of($path), $directory))->toBe($written);
})->with([
    'from the project' => ['src', '', 'src'],
    'from a directory beside it' => ['src', 'ci', '../src'],
    'from a directory that shares a parent' => ['ci/b/x.yml', 'ci/a', '../b/x.yml'],
    'the directory itself' => ['.', 'ci', '..'],
    'an absolute path from a directory of the project' => ['/etc/gate', 'ci', '/etc/gate'],
    'an absolute path from an absolute directory' => ['/project/src', '/project', 'src'],
    'an absolute path beside an absolute directory' => ['/shared/x', '/project', '../shared/x'],
    'a path from the project, from an absolute directory' => ['src', '/project', 'src'],
]);

it('reads a path a file in a directory of the project writes, from the project', function (
    string $written,
    string $directory,
    string $path,
): void {
    expect(ConfigPath::of($written, $directory)->path()->value())->toBe($path);
})->with([
    'from the project' => ['src', '', 'src'],
    'from a directory' => ['src', 'ci', 'ci/src'],
    'up from a directory' => ['../src', 'ci', 'src'],
    'up past the project' => ['../../src', 'ci', '../src'],
    'an absolute path' => ['/etc/gate', 'ci', '/etc/gate'],
]);
