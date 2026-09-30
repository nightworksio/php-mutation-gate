<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\PhpFile;
use NightWorksIO\MutationGate\Core\Proof\Key\Role;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFile;

$fingerprint = static fn(string $path): Fingerprint => Fingerprint::of(Path::of($path), Digest::of('9c1e'));

it('is a file of test cases, read for what it names', function () use ($fingerprint): void {
    $file = TestFile::testCase($fingerprint('tests/Unit/MoneyTest.php'), Contents::of("<?php\nit('adds', fn() => new Helper());\n"));

    expect($file->role())->toBe(Role::TestCase)
        ->and($file->fingerprint())->toEqual($fingerprint('tests/Unit/MoneyTest.php'))
        ->and($file->php())->toEqual(PhpFile::read(Contents::of("<?php\nit('adds', fn() => new Helper());\n")));
});

it('is support where it is PHP that only declares', function () use ($fingerprint): void {
    expect(TestFile::other($fingerprint('tests/Support/Helper.php'), Contents::of("<?php\nfinal class Helper {}\n"))->role())
        ->toBe(Role::Support);
});

it('is loaded where it runs something, or is not PHP', function (string $path, string $contents) use ($fingerprint): void {
    expect(TestFile::other($fingerprint($path), Contents::of($contents))->role())->toBe(Role::Loaded);
})->with([
    'PHP that runs something' => ['tests/Pest.php', "<?php\nuses(Base::class);\n"],
    'a fixture' => ['tests/fixtures/money.json', '{"amount": 1}'],
    'an empty fixture' => ['tests/fixtures/empty.txt', ''],
    'a file named for PHP but not ending in it' => ['tests/fixtures/helper.php.dist', "<?php\nfinal class Helper {}\n"],
]);

it('is loaded where it defines the runner, even where it only declares', function () use ($fingerprint): void {
    $file = TestFile::definition($fingerprint('tests/Pest.php'), Contents::of("<?php\nfunction helper(): void {}\n"));

    expect($file->role())->toBe(Role::Loaded)
        ->and($file->fingerprint())->toEqual($fingerprint('tests/Pest.php'))
        ->and($file->php())->toEqual(PhpFile::read(Contents::of("<?php\nfunction helper(): void {}\n")));
});
