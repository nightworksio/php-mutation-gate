<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestNamesRecord;
use NightWorksIO\MutationGate\Core\Test\TestRow;

/** The names a record holds, read back from its JSON. */
function testNamesRead(string $json): TestNames|string
{
    try {
        return TestNamesRecord::read(Node::decode($json));
    } catch (NotInShape $refused) {
        return $refused->getMessage();
    }
}

it('writes each id with its test\'s file and description, and a row\'s name, and reads them back', function (): void {
    $test = TestName::in(Path::of('tests/MoneyTest.php'), 'it adds');
    $names = TestNames::none()
        ->with(TestId::of('MoneyTest::adds'), $test)
        ->with(TestId::of('MoneyTest::adds#one'), TestRow::of($test, '"one"'));
    $written = TestNamesRecord::of($names);

    expect($written)->toBe([
        'MoneyTest::adds' => ['file' => 'tests/MoneyTest.php', 'description' => 'it adds'],
        'MoneyTest::adds#one' => ['file' => 'tests/MoneyTest.php', 'description' => 'it adds', 'row' => '"one"'],
    ])
        ->and(testNamesRead((string) json_encode($written)))->toEqual($names)
        ->and(testNamesRead('[]'))->toEqual(TestNames::none());
});

it('refuses names that are not a map of names, saying where', function (string $json, string $where): void {
    expect(testNamesRead($json))->toBeString()->toContain($where);
})->with([
    'not a map' => ['"names"', 'a map'],
    'a name with no file' => ['{"MoneyTest::adds": {"description": "it adds"}}', 'file'],
    'a row that is not text' => ['{"MoneyTest::adds": {"file": "a", "description": "b", "row": 1}}', 'row'],
]);

it('reads back a test whose id reads as a number', function (): void {
    $names = TestNames::none()->with(TestId::of('123'), TestName::in(Path::of('tests/NumberTest.php'), 'it counts'));

    expect(testNamesRead((string) json_encode(TestNamesRecord::of($names))))->toEqual($names);
});
