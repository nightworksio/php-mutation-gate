<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Installation;
use NightWorksIO\MutationGate\Adapter\PhpUnit\PhpFiles;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('lists a PHP file itself and every PHP file a directory holds, once each, in byte order', function (): void {
    $root = Scratch::directory();
    $names = array_map(static fn(int $n): string => sprintf('tests/%s.php', hash('sha256', (string) $n)), range(1, 24));

    foreach ($names as $name) {
        Scratch::write($root, $name, '<?php');
    }

    Scratch::write($root, 'tests/notes.md', '# notes');
    Scratch::write($root, 'checks/SmokeCheck.php', '<?php');
    Scratch::write($root, 'bin/tool', '#!/usr/bin/env php');
    $names[] = 'checks/SmokeCheck.php';
    sort($names);
    $installation = Installation::at($root, Paths::none(), Path::of('vendor'));
    $files = PhpFiles::in($installation, Paths::of(
        Path::of('tests'),
        Path::of('checks/SmokeCheck.php'),
        Path::of('tests'),
        Path::of('bin/tool'),
        Path::of('missing'),
    ));

    expect(array_map(static fn(Path $file): string => $file->value(), $files))->toBe($names);
});
