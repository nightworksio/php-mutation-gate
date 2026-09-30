<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Git\Blobs;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Tests\Support\FileTexts;

$blob = str_repeat('a', 40);

it('reads each blob the batch printed, by its size in bytes', function () use ($blob): void {
    $printed = sprintf("%s blob 6\n€ \0\n\n%s blob 0\n\n", $blob, $blob);

    expect(FileTexts::of(Blobs::read($printed, Paths::of(Path::of('src/Euro.php'), Path::of('src/Empty.php')))))
        ->toBe(['src/Euro.php' => "€ \0\n", 'src/Empty.php' => '']);
});

it('reads a name the batch found nothing or no blob at as missing, and carries on after it', function () use ($blob): void {
    $printed = sprintf("HEAD:./src/Gone.php missing\n%s tree 33\n%s\n%s blob 2\nok\nHEAD:./src ambiguous\n", $blob, str_repeat('t', 33), $blob);

    expect(FileTexts::of(Blobs::read($printed, Paths::of(Path::of('src/Gone.php'), Path::of('src'), Path::of('src/Ok.php'), Path::of('lib')))))
        ->toBe(['src/Gone.php' => null, 'src' => null, 'src/Ok.php' => 'ok', 'lib' => null]);
});

it('reads a name the batch printed nothing for as missing', function () use ($blob): void {
    expect(FileTexts::of(Blobs::read(sprintf("%s blob 2\nok\n", $blob), Paths::of(Path::of('src/Ok.php'), Path::of('src/Cut.php')))))
        ->toBe(['src/Ok.php' => 'ok', 'src/Cut.php' => null])
        ->and(FileTexts::of(Blobs::read('', Paths::of(Path::of('src/A.php')))))->toBe(['src/A.php' => null]);
});
