<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Inputs;

it('holds the digest of a unit\'s source, of what decides its mutant set, and of each killing test file', function (): void {
    $inputs = Inputs::of(Digest::sha256Of('source'), Digest::sha256Of('mutation'))
        ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test'));

    expect($inputs->source())->toEqual(Digest::sha256Of('source'))
        ->and($inputs->mutation())->toEqual(Digest::sha256Of('mutation'))
        ->and($inputs->testDigest(Path::of('tests/MoneyTest.php')))->toEqual(Digest::sha256Of('money test'))
        ->and($inputs->testDigest(Path::of('tests/TaxTest.php')))->toEqual(Missing::at(Path::of('tests/TaxTest.php')))
        ->and($inputs->tests())->toEqual(ByPath::none()->with(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test')))
        ->and(Inputs::of(Digest::sha256Of('source'), Digest::sha256Of('mutation'))->tests())->toHaveCount(0);
});
