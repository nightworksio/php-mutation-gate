<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestFiles;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

it('gives the files some tests are in, each once in the tests\' order, and none where one of them has no file', function (): void {
    $adds = TestId::of('MoneyTest::adds');
    $subtracts = TestId::of('MoneyTest::subtracts');
    $books = TestId::of('LedgerTest::books');
    $files = TestFiles::none()
        ->placing(Path::of('tests/MoneyTest.php'), TestIds::of($adds, $subtracts))
        ->placing(Path::of('tests/LedgerTest.php'), TestIds::of($books));

    expect($files->of(TestIds::of($books, $adds, $subtracts)))->toEqual(Paths::of(Path::of('tests/LedgerTest.php'), Path::of('tests/MoneyTest.php')))
        ->and($files->of(TestIds::of($adds)))->toEqual(Paths::of(Path::of('tests/MoneyTest.php')))
        ->and($files->of(TestIds::of($adds, TestId::of('OtherTest::runs'))))->toEqual(Paths::none())
        ->and($files->of(TestIds::none()))->toEqual(Paths::none())
        ->and([$files->count(), TestFiles::none()->count()])->toBe([3, 0])
        ->and(TestFiles::none()->of(TestIds::of($adds)))->toEqual(Paths::none());
});
