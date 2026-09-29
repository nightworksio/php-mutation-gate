<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFile;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFiles;
use NightWorksIO\MutationGate\Core\Proof\Key\Tests;

$case = static fn(string $path, string $source): TestFile => TestFile::testCase(
    Fingerprint::of(Path::of($path), Digest::of(sprintf('digest of %s', $path))),
    Contents::of($source),
);
$other = static fn(string $path, string $source): TestFile => TestFile::other(
    Fingerprint::of(Path::of($path), Digest::of(sprintf('digest of %s', $path))),
    Contents::of($source),
);
$paths = static fn(string ...$paths): Paths => Paths::of(...array_map(Path::of(...), $paths));
$values = static fn(Paths $paths): array => array_map(static fn(Path $path): string => $path->value(), iterator_to_array($paths, preserve_keys: true));

$files = TestFiles::of(
    $case('tests/Unit/MoneyTest.php', "<?php\nit('adds', fn() => new Helper());\n"),
    $case('tests/Unit/OtherTest.php', "<?php\nit('other', fn() => new Unused());\n"),
    $case('tests/Unit/UnknownTest.php', "<?php\nit('works');\n"),
    $case('tests/Unit/CanaryTest.php', "<?php\nit('sings', fn() => new Bird());\n"),
    $other('tests/Pest.php', "<?php\nuses(Base::class);\n"),
    $other('tests/fixtures/money.json', '{"amount": 1}'),
    $other('tests/Support/Helper.php', "<?php\nfinal class Helper { public function a(): Wing { return new Wing(); } }\n"),
    $other('tests/Support/Wing.php', "<?php\nfinal class Wing { public function b(): Helper { return new Helper(); } }\n"),
    $other('tests/Support/Base.php', "<?php\nabstract class Base {}\n"),
    $other('tests/Support/Bird.php', "<?php\nfinal class Bird {}\n"),
    $other('tests/Support/Unused.php', "<?php\nfinal class Unused {}\n"),
);
$known = $paths('tests/Unit/MoneyTest.php', 'tests/Unit/OtherTest.php', 'tests/Unit/CanaryTest.php');

it('reads the judging files, the support they name transitively, and what is in every key', function () use ($files, $known, $paths, $values): void {
    $tests = Tests::of($files, $known, $paths('tests/Unit/CanaryTest.php'));

    expect($values($tests->readBy($paths('tests/Unit/MoneyTest.php'))))->toBe([
        'tests/Unit/CanaryTest.php',
        'tests/Unit/UnknownTest.php',
        'tests/Pest.php',
        'tests/fixtures/money.json',
        'tests/Support/Base.php',
        'tests/Support/Bird.php',
        'tests/Unit/MoneyTest.php',
        'tests/Support/Helper.php',
        'tests/Support/Wing.php',
    ]);
});

it('reads no canary where the patch is off', function () use ($files, $known, $values): void {
    $tests = Tests::of($files, $known, Paths::none());

    expect($values($tests->readBy(Paths::none())))->toBe([
        'tests/Unit/UnknownTest.php',
        'tests/Pest.php',
        'tests/fixtures/money.json',
        'tests/Support/Base.php',
    ]);
});

it('follows what each file names before the files it named later', function () use ($other, $case, $paths, $values): void {
    $tests = Tests::of(
        TestFiles::of(
            $case('tests/ATest.php', "<?php\nit('a', fn() => [new B(), new C()]);\n"),
            $other('tests/B.php', "<?php\nfinal class B { public function d(): D {} }\n"),
            $other('tests/C.php', "<?php\nfinal class C { public function e(): E {} }\n"),
            $other('tests/D.php', "<?php\nfinal class D {}\n"),
            $other('tests/E.php', "<?php\nfinal class E {}\n"),
        ),
        $paths('tests/ATest.php'),
        Paths::none(),
    );

    expect($values($tests->readBy($paths('tests/ATest.php'))))
        ->toBe(['tests/ATest.php', 'tests/B.php', 'tests/C.php', 'tests/E.php', 'tests/D.php']);
});

it('reads a judge it holds no file of by its path alone', function () use ($files, $known, $paths, $values): void {
    $tests = Tests::of($files, $known, Paths::none());

    expect($values($tests->readBy($paths('tests/Unit/GoneTest.php')))[4])->toBe('tests/Unit/GoneTest.php')
        ->and($tests->digestOf(Path::of('tests/Unit/GoneTest.php')))->toEqual(Missing::at(Path::of('tests/Unit/GoneTest.php')))
        ->and($tests->digestOf(Path::of('tests/Pest.php')))->toEqual(Digest::of('digest of tests/Pest.php'));
});

it('names every file of test cases, each of which can judge a held unit', function () use ($files, $known, $values): void {
    expect($values(Tests::of($files, $known, Paths::none())->testCases()))->toBe([
        'tests/Unit/MoneyTest.php',
        'tests/Unit/OtherTest.php',
        'tests/Unit/UnknownTest.php',
        'tests/Unit/CanaryTest.php',
    ]);
});

it('reads a path named only by digits as a path', function () use ($other, $values): void {
    $tests = Tests::of(TestFiles::of($other('123', '{}')), Paths::none(), Paths::none());

    expect($values($tests->readBy(Paths::none())))->toBe(['123']);
});
