<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\WrittenPaths;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$ci = ConfigFile::at(Path::of('/project/ci/gate.json'), Path::of('/project'));

it('writes globs from the project as a file in a directory of it writes them', function () use ($ci): void {
    expect(WrittenPaths::globs($ci, [Glob::of('src/Gen/**'), Glob::of('ci/docs/**')]))->toBe(['../src/Gen/**', 'docs/**']);
});

it('writes the options of a choice that hold a path or a list of them from the file, and no other', function () use (
    $ci,
): void {
    $choice = Choice::of('directory', Configs::options('{"path": "ci/cache", "fallback": ["app"], "level": 3}'));

    expect(WrittenPaths::choice($choice, $ci, 'path', 'fallback', 'level', 'missing')->written())
        ->toEqual(Choice::of('directory', Configs::options('{"path": "cache", "fallback": ["../app"], "level": 3}'))->written());
});
