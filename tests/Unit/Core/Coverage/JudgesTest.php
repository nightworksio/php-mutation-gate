<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

it('knows of no file a test runs to begin with', function (): void {
    expect(Judges::none()->filesRunBy(Path::of('tests/MoneyTest.php')))->toEqual(Paths::none());
});

it('answers every covered file a test file judges, in the order they were added', function (): void {
    $judges = Judges::none()
        ->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/LedgerTest.php')))
        ->judging(Path::of('src/Clock.php'), Paths::of(Path::of('tests/ClockTest.php')))
        ->judging(Path::of('src/Ledger.php'), Paths::of(Path::of('tests/LedgerTest.php')));

    expect($judges->filesRunBy(Path::of('tests/LedgerTest.php')))->toEqual(Paths::of(Path::of('src/Money.php'), Path::of('src/Ledger.php')))
        ->and($judges->filesRunBy(Path::of('tests/ClockTest.php')))->toEqual(Paths::of(Path::of('src/Clock.php')))
        ->and($judges->filesRunBy(Path::of('tests/OtherTest.php')))->toEqual(Paths::none());
});

it('replaces the test files of a covered file judged again, and leaves the judges it came from as they were', function (): void {
    $judges = Judges::none()->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php')));
    $again = $judges->judging(Path::of('src/Money.php'), Paths::of(Path::of('tests/LedgerTest.php')));

    expect($again->filesRunBy(Path::of('tests/MoneyTest.php')))->toEqual(Paths::none())
        ->and($again->filesRunBy(Path::of('tests/LedgerTest.php')))->toEqual(Paths::of(Path::of('src/Money.php')))
        ->and($judges->filesRunBy(Path::of('tests/MoneyTest.php')))->toEqual(Paths::of(Path::of('src/Money.php')));
});
