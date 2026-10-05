<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestsByClass;
use NightWorksIO\MutationGate\Tests\Support\Growth;

/** Tests of three classes, interleaved, one of them a `.phpt` file's whole id. */
function testsByClass(): TestsByClass
{
    return TestsByClass::of(TestIds::of(
        TestId::of('Tests\MoneyTest::adds'),
        TestId::of('Tests\HeldTest::doubles'),
        TestId::of('Tests\MoneyTest::adds#1'),
        TestId::of('tests/money.phpt'),
        TestId::of('Tests\MoneyTest::subtracts'),
    ));
}

it('gives the tests of some classes, in the order they came', function (TestIds $given, array $tests): void {
    expect(array_map(static fn(TestId $test): string => $test->value(), [...$given]))->toBe($tests);
})->with([
    'one class' => [
        fn(): TestIds => testsByClass()->in(['Tests\MoneyTest']),
        ['Tests\MoneyTest::adds', 'Tests\MoneyTest::adds#1', 'Tests\MoneyTest::subtracts'],
    ],
    'two, asked out of order' => [
        fn(): TestIds => testsByClass()->in(['Tests\MoneyTest', 'Tests\HeldTest']),
        ['Tests\MoneyTest::adds', 'Tests\HeldTest::doubles', 'Tests\MoneyTest::adds#1', 'Tests\MoneyTest::subtracts'],
    ],
    'a test that names no method' => [fn(): TestIds => testsByClass()->in(['tests/money.phpt']), ['tests/money.phpt']],
    'a class of no test' => [fn(): TestIds => testsByClass()->in(['Tests\GoneTest']), []],
    'no class' => [fn(): TestIds => testsByClass()->in([]), []],
]);


it('wants the files named after each class', function (): void {
    $wanting = testsByClass()->wanting();

    expect($wanting->mayDeclare(Path::of('tests/MoneyTest.php')))->toBeTrue()
        ->and($wanting->mayDeclare(Path::of('tests/HeldTest.php')))->toBeTrue()
        ->and($wanting->mayDeclare(Path::of('tests/PriceTest.php')))->toBeFalse()
        ->and($wanting->byClass())->toBe([]);
});

it('gives one class\'s tests in time linear in how many that class has, however many others there are', function (): void {
    $asking = static function (int $size): Closure {
        $tests = TestsByClass::of(TestIds::of(...array_map(
            static fn(int $at): TestId => TestId::of(sprintf('Tests\Class%dTest::adds', $at)),
            range(1, $size),
        )));

        return static fn(): int => array_sum(array_map(
            static fn(int $at): int => count($tests->in([sprintf('Tests\Class%dTest', $at)])),
            range(1, $size),
        ));
    };

    expect($asking(10)())->toBe(10)
        ->and(Growth::of(500, $asking))->toBeLessThan(Growth::LINEAR);
});
