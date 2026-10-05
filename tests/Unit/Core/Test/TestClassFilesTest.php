<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestClassFiles;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Test\TestsByClass;

/** A test class's code, in a namespace or none. */
function testClassCode(string $namespace, string $class): Contents
{
    return Contents::of(
        $namespace === ''
            ? sprintf("<?php\nfinal class %s {}\n", $class)
            : sprintf("<?php\nnamespace %s;\nfinal class %s {}\n", $namespace, $class),
    );
}

it('reads only a file named after a class it wants', function (): void {
    $classes = TestClassFiles::wanting(['Tests\Unit\MoneyTest', 'GlobalTest']);

    expect($classes->mayDeclare(Path::of('tests/Unit/MoneyTest.php')))->toBeTrue()
        ->and($classes->mayDeclare(Path::of('tests/GlobalTest.php')))->toBeTrue()
        ->and($classes->mayDeclare(Path::of('tests/Unit/PriceTest.php')))->toBeFalse()
        ->and($classes->mayDeclare(Path::of('tests/Unit/Tests.php')))->toBeFalse();
});

it('finds a class in the file whose tokens declare it, and the first such file only', function (): void {
    $classes = TestClassFiles::wanting(['Tests\Unit\MoneyTest', 'GlobalTest'])
        ->readIn(Path::of('tests/Other/MoneyTest.php'), testClassCode('Tests\Other', 'MoneyTest'))
        ->readIn(Path::of('tests/GlobalTest.php'), testClassCode('', 'GlobalTest'))
        ->readIn(Path::of('tests/Unit/MoneyTest.php'), testClassCode('Tests\Unit', 'MoneyTest'))
        ->readIn(Path::of('tests/Copy/MoneyTest.php'), testClassCode('Tests\Unit', 'MoneyTest'));

    expect($classes->byClass())->toEqual([
        'GlobalTest' => Path::of('tests/GlobalTest.php'),
        'Tests\Unit\MoneyTest' => Path::of('tests/Unit/MoneyTest.php'),
    ])->and($classes->files())->toEqual(Paths::of(Path::of('tests/GlobalTest.php'), Path::of('tests/Unit/MoneyTest.php')));
});

it('finds nothing in a file not named after the class it declares', function (): void {
    $classes = TestClassFiles::wanting(['Tests\Unit\MoneyTest'])
        ->readIn(Path::of('tests/Unit/Misnamed.php'), testClassCode('Tests\Unit', 'MoneyTest'));

    expect($classes->byClass())->toBe([])
        ->and($classes->files())->toEqual(Paths::none());
});

it('wants the classes some tests are in, once each', function (): void {
    $tests = TestIds::of(
        TestId::of('Tests\Unit\MoneyTest::testAdds'),
        TestId::of('Tests\Unit\MoneyTest::testAdds#0'),
        TestId::of('GlobalTest::testRuns'),
    );
    $classes = TestClassFiles::of($tests)
        ->readIn(Path::of('tests/Unit/MoneyTest.php'), testClassCode('Tests\Unit', 'MoneyTest'))
        ->readIn(Path::of('tests/GlobalTest.php'), testClassCode('', 'GlobalTest'));

    expect($classes->files())->toEqual(Paths::of(Path::of('tests/Unit/MoneyTest.php'), Path::of('tests/GlobalTest.php')));
});

it('says whether every test is in a class it found', function (): void {
    $classes = TestClassFiles::wanting(['Tests\Unit\MoneyTest', 'Tests\Unit\PriceTest'])
        ->readIn(Path::of('tests/Unit/MoneyTest.php'), testClassCode('Tests\Unit', 'MoneyTest'));

    expect($classes->placeEach(TestIds::of(TestId::of('Tests\Unit\MoneyTest::testAdds'))))->toBeTrue()
        ->and($classes->placeEach(TestIds::none()))->toBeTrue()
        ->and($classes->placeEach(TestIds::of(
            TestId::of('Tests\Unit\MoneyTest::testAdds'),
            TestId::of('Tests\Unit\PriceTest::testRounds'),
        )))->toBeFalse();
});

it('names each test by its class file and its method, with its data set row, and leaves out the rest', function (): void {
    $adds = TestId::of('Tests\Unit\MoneyTest::testAdds');
    $row = TestId::of('Tests\Unit\MoneyTest::testAdds#0');
    $named = TestId::of('Tests\Unit\MoneyTest::testAdds#one');
    $tests = TestIds::of($adds, $row, $named, TestId::of('Tests\Unit\PriceTest::testRounds'), TestId::of('/t/x.phpt'));
    $names = TestClassFiles::of($tests)
        ->readIn(Path::of('tests/Unit/MoneyTest.php'), testClassCode('Tests\Unit', 'MoneyTest'))
        ->names($tests);
    $test = TestName::in(Path::of('tests/Unit/MoneyTest.php'), 'testAdds');

    expect($names)->toHaveCount(3)
        ->and($names->nameOf($adds))->toEqual($test)
        ->and($names->nameOf($row))->toEqual(TestRow::of($test, '#0'))
        ->and($names->nameOf($named))->toEqual(TestRow::of($test, '"one"'));
});

it('holds the tests of each class a test file declares, or that is named after one that is gone', function (): void {
    $tests = TestIds::of(
        TestId::of('Tests\Unit\MoneyTest::adds'),
        TestId::of('Tests\Unit\MoneyTest::adds#1'),
        TestId::of('Tests\Unit\HeldTest::doubles'),
        TestId::of('Tests\Unit\PriceTest::prices'),
    );
    $files = Paths::of(
        Path::of('tests/Unit/MoneyTest.php'),
        Path::of('tests/Unit/Unwanted.php'),
        Path::of('tests/Unit/HeldTest.php'),
    );

    $read = [];

    expect(TestClassFiles::held(
        TestsByClass::of($tests),
        $files,
        static function (Path $file) use (&$read): Contents|Missing {
            $read[] = $file->value();

            return $file->value() === 'tests/Unit/HeldTest.php' ? testClassCode('Tests\Unit', 'HeldTest') : Missing::at($file);
        },
    ))->toEqual(TestIds::of(
        TestId::of('Tests\Unit\MoneyTest::adds'),
        TestId::of('Tests\Unit\MoneyTest::adds#1'),
        TestId::of('Tests\Unit\HeldTest::doubles'),
    ))
        ->and($read)->toBe(['tests/Unit/MoneyTest.php', 'tests/Unit/HeldTest.php']);
});
