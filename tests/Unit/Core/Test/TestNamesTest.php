<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestRow;

it('names no test to begin with, and gives back the id of a test it has no name for', function (): void {
    $id = TestId::of('Tests\MoneyTest::testAdds');

    expect(TestNames::none())->toHaveCount(0)
        ->and(TestNames::none()->nameOf($id))->toBe($id)
        ->and(TestNames::none()->testOf($id))->toBe($id);
});

it('gives each id the name the runner gave it, the latest where it gave two', function (): void {
    $adds = TestId::of('P\Tests\Unit\MoneyTest::__pest_evaluable_it_adds');
    $test = TestName::in(Path::of('tests/Unit/MoneyTest.php'), 'it adds');
    $row = TestRow::of($test, '#0');
    $names = TestNames::none()->with($adds, $test)->with(TestId::of('Tests\PriceTest::testRounds'), $test);

    expect($names)->toHaveCount(2)
        ->and($names->nameOf($adds))->toBe($test)
        ->and($names->with($adds, $row)->nameOf($adds))->toBe($row)
        ->and($names->with($adds, $row))->toHaveCount(2);
});

it('folds a row into the whole test it is a row of', function (): void {
    $test = TestName::in(Path::of('tests/Unit/MoneyTest.php'), 'it adds');
    $names = TestNames::none()
        ->with(TestId::of('Tests\MoneyTest::testAdds#0'), TestRow::of($test, '#0'))
        ->with(TestId::of('Tests\MoneyTest::testAdds'), $test);

    expect($names->testOf(TestId::of('Tests\MoneyTest::testAdds#0')))->toBe($test)
        ->and($names->testOf(TestId::of('Tests\MoneyTest::testAdds')))->toBe($test);
});

it('lists tests by the names the runner gave them, and by their ids where it gave none', function (): void {
    $test = TestName::in(Path::of('tests/Unit/MoneyTest.php'), 'it fits');
    $names = TestNames::none()
        ->with(TestId::of('MoneyTest::fits'), $test)
        ->with(TestId::of('MoneyTest::refuses'), TestRow::of($test, '"over"'));

    expect($names->listed(TestIds::of(TestId::of('MoneyTest::fits'), TestId::of('MoneyTest::refuses'), TestId::of('PriceTest::adds'))))
        ->toBe('tests/Unit/MoneyTest.php::it fits, tests/Unit/MoneyTest.php::it fits with data set "over", PriceTest::adds')
        ->and($names->listed(TestIds::of()))->toBe('');
});

it('lists a name a project wrote with line breaks, escapes and workflow commands as one plain line', function (): void {
    $names = TestNames::none()->with(
        TestId::of('MoneyTest::fits'),
        TestName::in(Path::of('tests/Unit/MoneyTest.php'), "it \e[31mfits\r\n::error::forged\tthere"),
    );

    expect($names->listed(TestIds::of(TestId::of('MoneyTest::fits'), TestId::of('PriceTest::adds'))))
        ->toBe('tests/Unit/MoneyTest.php::it [31mfits ::error::forged there, PriceTest::adds');
});
