<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFile;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFiles;

$file = static fn(string $path, string $digest): TestFile => TestFile::other(
    Fingerprint::of(Path::of($path), Digest::of($digest)),
    Contents::of('{}'),
);
$digests = static fn(TestFiles $files): array => array_map(
    static fn(TestFile $file): string => $file->fingerprint()->digest()->value(),
    iterator_to_array($files, preserve_keys: true),
);

it('holds one file per path, a later one replacing the earlier', function () use ($file, $digests): void {
    $files = TestFiles::of($file('tests/b.json', '1'), $file('tests/a.json', '2'), $file('tests/b.json', '3'));

    expect($digests($files))->toBe(['3', '2'])
        ->and($files)->toHaveCount(2)
        ->and(TestFiles::of())->toHaveCount(0);
});
