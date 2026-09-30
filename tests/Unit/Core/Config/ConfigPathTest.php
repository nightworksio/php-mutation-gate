<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\ConfigPath;

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
