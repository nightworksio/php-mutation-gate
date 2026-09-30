<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\PhpFiles;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
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
    $project = Project::at($root, Path::of('vendor'), Path::of('.mutation-gate'));

    $files = PhpFiles::in(
        $project,
        Paths::of(Path::of('src'), Path::of('src/B.php'), Path::of('bin/tool'), Path::of('missing')),
        Paths::of(Path::of('src/Legacy')),
    );

    expect(array_map(static fn(Path $file): string => $file->value(), $files))->toBe(['src/B.php', 'src/a/A.php']);
});
