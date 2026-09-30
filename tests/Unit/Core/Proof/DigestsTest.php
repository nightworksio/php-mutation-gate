<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Undigested;

$digests = Digests::of(Digest::sha256Of('mutation'))
    ->withSource(Path::of('src/Money.php'), Digest::sha256Of('money'))
    ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test'))
    ->withTest(Path::of('tests/TaxTest.php'), Digest::sha256Of('tax test'));

it('holds a run\'s digests: what decides every mutant set, each unit\'s source, and each test file', function () use ($digests): void {
    expect($digests->mutation())->toEqual(Digest::sha256Of('mutation'))
        ->and($digests->sourceOf(Path::of('src/Money.php')))->toEqual(Digest::sha256Of('money'))
        ->and($digests->sourceOf(Path::of('src/Tax.php')))->toEqual(Missing::at(Path::of('src/Tax.php')))
        ->and($digests->testOf(Path::of('tests/TaxTest.php')))->toEqual(Digest::sha256Of('tax test'))
        ->and($digests->testOf(Path::of('tests/GoneTest.php')))->toEqual(Missing::at(Path::of('tests/GoneTest.php')))
        ->and($digests->sources())->toHaveCount(1)
        ->and($digests->tests())->toHaveCount(2);
});

it('gives a proof of a unit its share, with each killing test file it has a digest of', function () use ($digests): void {
    expect($digests->inputsOf(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/GoneTest.php'))))
        ->toEqual(Inputs::of(Digest::sha256Of('money'), Digest::sha256Of('mutation'))->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test')));
});

it('gives no inputs to a proof of a unit whose source it has no digest of', function () use ($digests): void {
    expect($digests->inputsOf(Path::of('src/Tax.php'), Paths::none()))->toEqual(Undigested::proof());
});
