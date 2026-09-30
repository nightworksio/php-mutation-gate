<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\ProjectPath;
use NightWorksIO\MutationGate\Core\File\Path;

it('spells a path from the project from the project, and an absolute one from the file system\'s root', function (): void {
    $inside = ProjectPath::of('./build//reports');
    $outside = ProjectPath::of('/tmp/reports');

    expect($inside->value())->toBe('build/reports')
        ->and($inside->directory())->toEqual(Directory::at('.'))
        ->and($inside->inside())->toEqual(Path::of('build/reports'))
        ->and($outside->value())->toBe('/tmp/reports')
        ->and($outside->directory())->toEqual(Directory::at('/'))
        ->and($outside->inside())->toEqual(Path::of('tmp/reports'));
});

it('names an entry under it', function (): void {
    expect(ProjectPath::of('build')->child('index.html')->value())->toBe('build/index.html')
        ->and(ProjectPath::of('/tmp')->child('index.html')->inside())->toEqual(Path::of('tmp/index.html'));
});
