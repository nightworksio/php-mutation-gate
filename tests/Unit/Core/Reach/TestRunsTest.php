<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Reach\TestRuns;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

$makeMap = static fn(): CoverageMap => CoverageMap::of(
    CoveredLine::of(Path::of('src/Money.php'), 3, 'MoneyTest::adds', 'RateTest::rates'),
    CoveredLine::of(Path::of('src/Money.php'), 4, 'MoneyTest::adds'),
    CoveredLine::of(Path::of('src/Rate.php'), 2, 'RateTest::rates', 'MoneyTest::rounds'),
    CoveredLine::of(Path::of('src/Clock.php'), 7, 'ClockTest::ticks'),
    CoveredLine::of(Path::of('src/Ledger.php'), 1, 'MoneyTest::rounds'),
);

$makePlaces = static fn(): TestPlaces => TestPlaces::none()
    ->placing(Path::of('tests/MoneyTest.php'), TestIds::of(TestId::of('MoneyTest::adds'), TestId::of('MoneyTest::rounds')))
    ->placing(Path::of('tests/RateTest.php'), TestIds::of(TestId::of('RateTest::rates')));

it('says which files the tests placed in the changed test files cover, each once, in the order the map lists them', function () use ($makeMap, $makePlaces): void {
    $runs = TestRuns::of($makeMap(), $makePlaces());

    expect($runs->narrows())->toBeTrue()
        ->and($runs->filesRunBy(TestIds::of(TestId::of('MoneyTest::rounds'), TestId::of('MoneyTest::adds'))))
        ->toEqual(Paths::of(Path::of('src/Money.php'), Path::of('src/Rate.php'), Path::of('src/Ledger.php')))
        ->and($runs->filesRunBy(TestIds::of(TestId::of('RateTest::rates'))))->toEqual(Paths::of(Path::of('src/Money.php'), Path::of('src/Rate.php')))
        ->and($runs->in(Path::of('tests/MoneyTest.php')))->toEqual(TestIds::of(TestId::of('MoneyTest::adds'), TestId::of('MoneyTest::rounds')))
        ->and($runs->in(Path::of('tests/ClockTest.php')))->toEqual(TestIds::none());
});

it('says nothing of a test placed in no changed test file, or one the map does not know', function () use ($makeMap, $makePlaces): void {
    $runs = TestRuns::of($makeMap(), $makePlaces());

    expect($runs->filesRunBy(TestIds::of(TestId::of('ClockTest::ticks'))))->toEqual(Paths::none())
        ->and($runs->filesRunBy(TestIds::of(TestId::of('MoneyTest::gone'))))->toEqual(Paths::none())
        ->and($runs->filesRunBy(TestIds::none()))->toEqual(Paths::none());
});

it('narrows nothing and places no test where every changed test file reaches all its tests run', function (): void {
    $runs = TestRuns::wholeFiles();

    expect($runs->narrows())->toBeFalse()
        ->and($runs->in(Path::of('tests/MoneyTest.php')))->toEqual(TestIds::none())
        ->and($runs->filesRunBy(TestIds::of(TestId::of('MoneyTest::adds'))))->toEqual(Paths::none());
});
