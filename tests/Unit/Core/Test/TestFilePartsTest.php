<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\TestFileParts;

$times = ['tests/A.php' => 5.0, 'tests/B.php' => 4.0, 'tests/C.php' => 1.0, 'tests/D.php' => 1.0];

it('places each test file, longest first, in the part with least time so far, ties broken by name', function () use ($times): void {
    expect(TestFileParts::placed($times, 2))->toBe(['tests/A.php' => 0, 'tests/B.php' => 1, 'tests/C.php' => 1, 'tests/D.php' => 0])
        ->and(TestFileParts::placed($times, 3))->toBe(['tests/A.php' => 0, 'tests/B.php' => 1, 'tests/C.php' => 2, 'tests/D.php' => 2]);
});

it('places files of no time by name, each in the part with the fewest files so far', function (): void {
    expect(TestFileParts::placed(['tests/D.php' => 0.0, 'tests/B.php' => 0.0, 'tests/C.php' => 0.0, 'tests/A.php' => 0.0], 2))
        ->toBe(['tests/A.php' => 0, 'tests/B.php' => 1, 'tests/C.php' => 0, 'tests/D.php' => 1]);
});

it('places each file in a part of its own where there are more parts than files, and every file in one part of one', function () use ($times): void {
    expect(TestFileParts::placed($times, 9))->toBe(['tests/A.php' => 0, 'tests/B.php' => 1, 'tests/C.php' => 2, 'tests/D.php' => 3])
        ->and(TestFileParts::placed($times, 0))->toBe(['tests/A.php' => 0, 'tests/B.php' => 0, 'tests/C.php' => 0, 'tests/D.php' => 0])
        ->and(TestFileParts::placed([], 3))->toBe([]);
});

it('places alike however the files came, and places whole counts as it places times', function () use ($times): void {
    expect(TestFileParts::placed(array_reverse($times, preserve_keys: true), 3))->toBe(TestFileParts::placed($times, 3))
        ->and(TestFileParts::placed(['src/b.php' => 2, 'src/a.php' => 5, 'src/c.php' => 2], 2))
        ->toBe(['src/a.php' => 0, 'src/b.php' => 1, 'src/c.php' => 1]);
});
