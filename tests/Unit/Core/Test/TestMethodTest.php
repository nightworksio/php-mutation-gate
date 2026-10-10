<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestRow;

it('reads a coverage id\'s class and method, and names the test or the row it runs', function (
    string $id,
    string $class,
    string $method,
    string $row,
): void {
    $test = TestMethod::of(TestId::of($id));
    $name = TestName::in(Path::of('tests/MoneyTest.php'), 'adds');

    expect($test)->toBeInstanceOf(TestMethod::class)
        ->and($test instanceof TestMethod ? [$test->className(), $test->method()] : [])->toBe([$class, $method])
        ->and($test instanceof TestMethod ? $test->in(Path::of('tests/MoneyTest.php'), 'adds') : $test)
        ->toEqual($row === '' ? $name : TestRow::of($name, $row));
})->with([
    'a test method' => ['Tests\MoneyTest::testAdds', 'Tests\MoneyTest', 'testAdds', ''],
    'a numbered row' => ['Tests\MoneyTest::testAdds#12', 'Tests\MoneyTest', 'testAdds', '#12'],
    'a named row' => ['Tests\MoneyTest::testAdds#one', 'Tests\MoneyTest', 'testAdds', '"one"'],
    'a row named with a number first' => ['Tests\MoneyTest::testAdds#1a', 'Tests\MoneyTest', 'testAdds', '"1a"'],
    'a row named with a hash' => ['Tests\MoneyTest::testAdds#one#two', 'Tests\MoneyTest', 'testAdds', '"one#two"'],
    'a row with no name' => ['Tests\MoneyTest::testAdds#', 'Tests\MoneyTest', 'testAdds', '""'],
    'a Pest data set' => ['P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "one"', 'P\Tests\MoneySpec', '__pest_evaluable_it_adds', '"dataset "one""'],
    'a class in no namespace' => ['LegacySpec::decrements', 'LegacySpec', 'decrements', ''],
    'a row across lines' => ["Tests\\MoneyTest::testAdds#one\ntwo", 'Tests\MoneyTest', 'testAdds', "\"one\ntwo\""],
]);

it('names the test as PHPUnit names it for a filter, a row with its data set', function (string $id, string $named): void {
    $test = TestMethod::of(TestId::of($id));

    expect($test instanceof TestMethod ? $test->named() : $test)->toBe($named);
})->with([
    'a test method' => ['Tests\MoneyTest::testAdds', 'Tests\MoneyTest::testAdds'],
    'a numbered row' => ['Tests\MoneyTest::testAdds#12', 'Tests\MoneyTest::testAdds with data set #12'],
    'a named row' => ['Tests\MoneyTest::testAdds#384 bits', 'Tests\MoneyTest::testAdds with data set "384 bits"'],
]);

it('reads an id of another shape as no test method, in a class of its whole id', function (string $id): void {
    expect(TestMethod::of(TestId::of($id)))->toEqual(TestId::of($id))
        ->and(TestMethod::classOf(TestId::of($id)))->toBe($id);
})->with([
    'no method' => ['Tests\MoneyTest'],
    'an empty method' => ['Tests\MoneyTest::'],
    'a phpt file' => ['tests/money.phpt'],
]);

it('reads a test method\'s class', function (): void {
    expect(TestMethod::classOf(TestId::of('Tests\MoneyTest::testAdds#one')))->toBe('Tests\MoneyTest');
});

it('reads the class an id\'s test is in as the test method it runs names it', function (string $id, string $class): void {
    $method = TestMethod::of(TestId::of($id));

    expect(TestMethod::classOf(TestId::of($id)))->toBe($class)
        ->and($method instanceof TestMethod ? $method->className() : $method->value())->toBe($class);
})->with([
    'a method after the first separator' => ['A::b', 'A'],
    'a class in no name' => ['::b', ''],
    'a method that starts with a colon' => ['A:::b', 'A'],
    'a row where a method should be, then a method' => ['A::#b::c', 'A::#b'],
    'an empty method, then a method' => ['A::::c', 'A'],
    'a row where a method should be, and no other separator' => ['A::#b', 'A::#b'],
    'separators alone' => ['::::', ''],
]);

it('spells a test method\'s id from its class and method', function (): void {
    expect(TestMethod::id('Tests\MoneyTest', 'testAdds'))->toEqual(TestId::of('Tests\MoneyTest::testAdds'));
});
