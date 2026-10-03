<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestPaths;

it('is the tests of some files', function (): void {
    $files = Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/HeldTest.php'));

    expect(TestPaths::of($files)->files())->toBe($files);
});
