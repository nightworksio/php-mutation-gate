<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\Affected;

it('holds each test file with its tests, in the order they were placed', function (): void {
    $places = Affected::places();

    expect($places->files())->toEqual(Paths::of(
        Path::of('tests/MoneyTest.php'),
        Path::of('tests/PriceTest.php'),
        Path::of('tests/RateTest.php'),
    ))->and($places->in(Path::of('tests/PriceTest.php')))->toEqual(Affected::ids('PriceTest::totals'))
        ->and($places->in(Path::of('src/Money.php')))->toEqual(TestIds::none());
});

it('finds the files that hold some tests, each with those it holds, and none for a test no file holds', function (): void {
    expect(Affected::places()->holding(Affected::ids('RateTest::rates', 'Gone::test', 'MoneyTest::adds', 'MoneyTest::adds nothing')))
        ->toEqual([
            [Path::of('tests/RateTest.php'), Affected::ids('RateTest::rates')],
            [Path::of('tests/MoneyTest.php'), Affected::ids('MoneyTest::adds', 'MoneyTest::adds nothing')],
        ]);
});

it('places a file again in place of what it held', function (): void {
    $places = TestPlaces::none()
        ->placing(Path::of('tests/MoneyTest.php'), Affected::ids('MoneyTest::adds', 'MoneyTest::old'))
        ->placing(Path::of('tests/MoneyTest.php'), Affected::ids('MoneyTest::adds'));

    expect($places->in(Path::of('tests/MoneyTest.php')))->toEqual(Affected::ids('MoneyTest::adds'))
        ->and($places->holding(Affected::ids('MoneyTest::old')))->toBe([])
        ->and([...$places->files()])->toHaveCount(1);
});
