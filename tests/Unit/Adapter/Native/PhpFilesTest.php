<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\PhpFiles;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('lists a PHP file itself and every PHP file a directory holds, once each, in byte order, but those left out', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/B.php', '<?php');
    Scratch::write($root, 'src/a/A.php', '<?php');
    Scratch::write($root, 'src/a/notes.md', '# notes');
    Scratch::write($root, 'src/Legacy/Old.php', '<?php');
    Scratch::write($root, 'bin/tool', '#!/usr/bin/env php');
    $project = Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit);

    $files = PhpFiles::in(
        $project,
        Paths::of(Path::of('src'), Path::of('src/B.php'), Path::of('bin/tool'), Path::of('missing')),
        Paths::of(Path::of('src/Legacy')),
    );

    expect(array_map(static fn(Path $file): string => $file->value(), $files))->toBe(['src/B.php', 'src/a/A.php']);
});

it('lists many files in byte order, whatever order the directory hands them over in', function (): void {
    $root = Scratch::directory();
    $names = array_map(static fn(int $n): string => sprintf('src/%s.php', hash('sha256', (string) $n)), range(1, 24));

    foreach ($names as $name) {
        Scratch::write($root, $name, '<?php');
    }

    sort($names);
    $project = Project::at($root, Paths::none(), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit);

    expect(array_map(static fn(Path $file): string => $file->value(), PhpFiles::in($project, Paths::of(Path::of('src')), Paths::none())))
        ->toBe($names);
});
