<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/** Every setting that names a path or a glob, as `ci/gate.json` writes it. */
const FROM_CI = [
    'runner' => 'pest',
    'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['../app']]],
    'trees' => [['path' => '../src', 'exclude' => ['../src/Gen/**']]],
    'packages' => ['../packages/*'],
    'reach' => ['everything' => ['config/**']],
    'costs' => ['secondsPerLine' => ['' => 0.1, '../src/Legacy' => 2]],
    'proofs' => ['store' => ['use' => 'directory', 'with' => ['path' => 'cache']], 'ignore' => ['docs/**']],
    'ignores' => ['entries' => [
        ['path' => '../src/Log/**', 'mutator' => 'MethodCallRemoval', 'reason' => 'Logging is asserted elsewhere'],
    ]],
];

$file = ConfigFile::at(Path::of('/project/ci/gate.json'), Path::of('/project'));

/** @return mixed what the layer `ci/gate.json` holds writes at this origin, or its problems */
$read = static function (PathOrigin $origin) use ($file): mixed {
    $layer = Configs::layer(FROM_CI, $file);

    return $layer instanceof Layer ? Configs::decoded($layer, $origin) : Configs::problems($layer);
};

it('names every path and glob a file writes from the file\'s directory', function () use ($read): void {
    expect($read(ProjectRoot::origin()))->toBe([
        'runner' => 'pest',
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app']]],
        'trees' => [['path' => 'src', 'exclude' => ['src/Gen/**']]],
        'packages' => ['packages/*'],
        'reach' => ['everything' => ['ci/config/**']],
        'costs' => ['secondsPerLine' => ['' => 0.1, 'src/Legacy' => 2]],
        'proofs' => ['store' => ['use' => 'directory', 'with' => ['path' => 'ci/cache']], 'ignore' => ['ci/docs/**']],
        'ignores' => ['entries' => [
            ['path' => 'src/Log/**', 'mutator' => 'MethodCallRemoval', 'reason' => 'Logging is asserted elsewhere'],
        ]],
    ]);
});

it('writes each of them back as the file wrote it', function () use ($read, $file): void {
    expect($read($file))->toEqualCanonicalizing(FROM_CI);
});
