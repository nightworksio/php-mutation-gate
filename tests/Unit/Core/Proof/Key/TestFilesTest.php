<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
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

it('names the files that declare one of some classes', function (): void {
    $declaring = static fn(string $path, string $source): TestFile => TestFile::other(
        Fingerprint::of(Path::of($path), Digest::of($path)),
        Contents::of($source),
    );
    $files = TestFiles::of(
        $declaring('tests/Mutators/PlusToMinus.php', "<?php\nnamespace Tests;\nfinal class PlusToMinus {}\n"),
        $declaring('tests/Support/Money.php', "<?php\nfinal class Money {}\n"),
    );

    expect($files->declaring('Acme\\Mutators\\PlusToMinus'))->toEqual(Paths::of(Path::of('tests/Mutators/PlusToMinus.php')))
        ->and($files->declaring())->toEqual(Paths::none());
});
