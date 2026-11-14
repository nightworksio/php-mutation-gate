<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\OpArrays;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('keeps what a child answered before it ran out of time, and compiles nothing where none could start', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'php', "#!/bin/sh\necho 1.2.3\nsleep 5\n");
    chmod(sprintf('%s/php', $directory), 0o755);
    $programs = ['a' => Contents::of("<?php\n")];
    $stalled = new OpArrays(sprintf('%s/php', $directory), '/nowhere/outbreak.so', sprintf('%s/programs', $directory), 0.5);
    $missing = new OpArrays(sprintf('%s/none', $directory), '/nowhere/outbreak.so', sprintf('%s/programs', $directory), 0.5);

    expect($stalled->digests($programs))->toBe([[], '1.2.3'])
        ->and($missing->digests($programs))->toEqual(NotGiven::value());
});
