<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Problem;
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

it('reads a copy kept elsewhere in the file\'s place, naming its paths from the file\'s directory', function () use ($read): void {
    $copy = ConfigFile::copyOf(Path::of('/project/.mutation-gate/base/gate.json'), Path::of('/project/ci/gate.json'), Path::of('/project'));
    $layer = Configs::layer(FROM_CI, $copy);

    expect($copy->file())->toEqual(Path::of('/project/.mutation-gate/base/gate.json'))
        ->and($copy->extension())->toBe('json')
        ->and($layer instanceof Layer ? Configs::decoded($layer, ProjectRoot::origin()) : Configs::problems($layer))
        ->toBe($read(ProjectRoot::origin()));
});

it('writes each of them back as the file wrote it', function () use ($read, $file): void {
    expect($read($file))->toEqualCanonicalizing(FROM_CI);
});

it('refuses an absolute path, and one that lands outside the project', function () use ($file): void {
    expect(Configs::problems(Configs::layer(['trees' => [['path' => '/project/src'], ['path' => '../../src']]], $file)))
        ->toBe([
            'trees[0].path: expected a path inside the project, got "/project/src"',
            'trees[1].path: expected a path inside the project, got "../../src"',
        ]);
});

it('names the paths of a file outside the project from its directory, and takes those that land inside', function (): void {
    $outside = ConfigFile::at(Path::of('/shared/gate.json'), Path::of('/project'));
    $inside = Configs::layer(['trees' => [['path' => '../project/src']]], $outside);

    expect($outside->path(Path::of('../project/src')))->toEqual(Path::of('src'))
        ->and($outside->written(Path::of('src')))->toBe('../project/src')
        ->and($inside instanceof Layer ? Configs::decoded($inside) : Configs::problems($inside))
        ->toBe(['trees' => [['path' => 'src']]])
        ->and(Configs::problems(Configs::layer(['trees' => [['path' => 'src']]], $outside)))
        ->toBe(['trees[0].path: expected a path inside the project, got "src"']);
});

it('names the paths of a file the project spells from the project as a file inside it', function (): void {
    $file = ConfigFile::at(Path::of('ci/gate.json'), Path::of('/project'));

    expect($file->path(Path::of('../src')))->toEqual(Path::of('src'))
        ->and($file->written(Path::of('src')))->toBe('../src');
});

it('hands another adapter its options with their paths named from the file, and refused where they lead out', function () use (
    $file,
): void {
    $layer = Configs::layer([
        'proofs' => ['store' => ['use' => 'Acme\Store', 'with' => ['path' => '../cache', 'up' => '../../cache']]],
        'reports' => [['use' => 'acme', 'path' => 'mutation.json', 'with' => ['into' => '/tmp']]],
    ], $file);
    $store = $layer instanceof Layer ? $layer->proofs()->store()->options() : $layer;
    $report = $layer instanceof Layer ? [...$layer->reports()->reports()][0]->reporter()->options() : $layer;

    expect($store instanceof Options ? $store->path(Key::of('path')) : $store)->toEqual(Path::of('cache'))
        ->and($store instanceof Options ? $store->path(Key::of('up')) : $store)
        ->toEqual(Problem::at('up', 'expected a path inside the project, got "../../cache"'))
        ->and($report instanceof Options ? $report->path(Key::of('into')) : $report)
        ->toEqual(Problem::at('into', 'expected a path inside the project, got "/tmp"'));
});
