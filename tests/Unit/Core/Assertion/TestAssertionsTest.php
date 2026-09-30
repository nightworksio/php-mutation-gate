<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\Assertion;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\Assertion\TestAssertions;
use NightWorksIO\MutationGate\Core\Assertion\WeaklyAsserted;
use NightWorksIO\MutationGate\Core\Assertion\WeakTest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use NightWorksIO\MutationGate\Tests\Support\Weakly;

/** A Pest test file, and a PHPUnit one, each holding tests weak and not. */
const PEST_CART_TEST = 'tests/Fixtures/Assertion/cart.pest.txt';

const PHPUNIT_CART_TEST = 'tests/Fixtures/Assertion/cart.phpunit.txt';

/**
 * The assertions of a test as written, each with what it checks, and whether they are weak.
 *
 * @return array{list<string>, bool}
 */
function readAssertions(string $file, string $description): array
{
    $text = is_file(Tree::at($file)) ? (string) file_get_contents(Tree::at($file)) : $file;
    $assertions = TestAssertions::in(Contents::of($text))->of($description);

    return [
        array_map(
            static fn(Assertion $assertion): string => sprintf('%s %s', $assertion->written(), $assertion->kind()->value),
            iterator_to_array($assertions, preserve_keys: false),
        ),
        $assertions->isWeak(),
    ];
}

it('reads a Pest test\'s expectations in order, with each negation', function (): void {
    expect(readAssertions(PEST_CART_TEST, 'it adds'))->toBe([
        ['->not->toBeNull() existence', '->toBeInt() shape', '->toBeInstanceOf() shape', '->not->toHaveCount() shape'],
        true,
    ]);
});

it('reads a test as weak only when every assertion checks existence or shape', function (string $file, string $description, array $read): void {
    expect(readAssertions($file, $description))->toBe($read);
})->with([
    'an expected exception' => [PEST_CART_TEST, 'refuses', [['->toThrow() value'], false]],
    'a chained exception' => [PEST_CART_TEST, 'it throws', [['->throws() value'], false]],
    'a value beside a shape' => [PEST_CART_TEST, 'it mixes', [['assertSame value', '->toBeInt() shape'], false]],
    'a description with quotes' => [PEST_CART_TEST, "it escapes 'quotes'", [['->toBeArray() shape'], true]],
    'PHPUnit\'s existence and shape' => [PHPUNIT_CART_TEST, 'testAdds', [['assertNotNull existence', 'assertIsArray shape', 'assertTrue existence'], true]],
    'a comparison' => [PHPUNIT_CART_TEST, 'testValue', [['assertTrue value'], false]],
    'a function of PHPUnit, and a method named like an expectation' => [PHPUNIT_CART_TEST, 'testCallsIt', [['assertCount shape'], true]],
]);

it('reads what Pest chains after expect(), qualified or not, as each call checks', function (string $description, array $read): void {
    expect(readAssertions(PEST_CART_TEST, $description))->toBe($read);
})->with([
    'the items of a subject, each' => ['it checks each item', [['->toBeArray() shape', '->toBeInt() shape'], true]],
    'expect(), qualified from the root' => ['it qualifies', [['->toBe() value', '->toBeInt() shape'], false]],
    'expect(), qualified' => ['it qualifies relatively', [['->toBe() value', '->toBeInt() shape'], false]],
    'a key with its value' => ['it checks a key', [['->toHaveKey() value'], false]],
    'properties with their values' => ['it checks properties', [['->toHaveProperties() value'], false]],
    'properties by name' => ['it names properties', [['->toHaveProperties() existence'], true]],
    'an exception, if' => ['it throws if', [['->throwsIf() value'], false]],
    'a subject\'s JSON' => ['it checks json', [['->toHaveKey() existence'], true]],
    'a new subject' => ['it checks both', [['->toBeInt() shape', '->not->toBeNull() existence'], true]],
    'a class declared in the test' => ['it builds a spy', [['->toBeObject() shape'], true]],
    'the undescribed test of a description a described one shares' => ['it described', [['->toBe() value'], false]],
]);

it('reads PHPUnit\'s assertions however a test calls them', function (string $description, array $read): void {
    expect(readAssertions(PHPUNIT_CART_TEST, $description))->toBe($read);
})->with([
    'as a qualified function' => ['testQualified', [['assertNotNull existence', 'assertSame value'], false]],
    'in another case' => ['testCased', [['assertNotNull existence', 'AssertSame value'], false]],
    'true, with a message' => ['testMessage', [['assertTrue existence'], true]],
    'not the same as null' => ['testNotSameAsNull', [['assertNotSame existence'], true]],
    'with a helper\'s name, on the subject' => ['testCallsTheSubject', [['assertNotNull existence'], true]],
]);

it('assesses no test that makes an assertion the table does not hold, makes none, or is not in the file', function (string $file, string $description): void {
    expect(readAssertions($file, $description))->toBe([[], false]);
})->with([
    'a project\'s own expectation' => [PEST_CART_TEST, 'it checks money'],
    'a project\'s own assertion' => [PHPUNIT_CART_TEST, 'testMine'],
    'no assertion' => [PEST_CART_TEST, 'it does nothing'],
    'no such test' => [PEST_CART_TEST, 'it is not there'],
    'no such method' => [PHPUNIT_CART_TEST, 'testGone'],
    'a method with no body, before one with a body' => [PHPUNIT_CART_TEST, 'testLater'],
    'a chained call that checks items one by one' => [PEST_CART_TEST, 'it sequences'],
    'a chained call that checks each item' => [PEST_CART_TEST, 'it checks each'],
    'a chained call that checks when' => [PEST_CART_TEST, 'it checks when'],
    'a helper the file declares, as a function' => [PEST_CART_TEST, 'it delegates'],
    'a helper the file declares, on $this' => [PHPUNIT_CART_TEST, 'testDelegates'],
    'a helper the file declares, on self' => [PHPUNIT_CART_TEST, 'testDelegatesStatically'],
    'a file that is not PHP' => ['not php', 'it adds'],
]);

it('tags each assertion with the style it is written in', function (string $file, string $description, AssertionStyle ...$styles): void {
    $assertions = TestAssertions::in(Contents::of((string) file_get_contents(Tree::at($file))))->of($description);

    expect(array_map(static fn(Assertion $assertion): AssertionStyle => $assertion->style(), iterator_to_array($assertions, preserve_keys: false)))
        ->toBe($styles);
})->with([
    'PHPUnit\'s, on $this and self' => [PHPUNIT_CART_TEST, 'testAdds', AssertionStyle::PhpUnit, AssertionStyle::PhpUnit, AssertionStyle::PhpUnit],
    'PHPUnit\'s, on static' => [PHPUNIT_CART_TEST, 'testValue', AssertionStyle::PhpUnit],
    'PHPUnit\'s, as a function' => [PHPUNIT_CART_TEST, 'testCallsIt', AssertionStyle::PhpUnit],
    'Pest\'s, negated and not' => [PEST_CART_TEST, 'it adds', AssertionStyle::Pest, AssertionStyle::Pest, AssertionStyle::Pest, AssertionStyle::Pest],
    'a Pest test\'s chained exception' => [PEST_CART_TEST, 'it throws', AssertionStyle::Pest],
    'both, in a PHPUnit class that Pest runs' => [PHPUNIT_CART_TEST, 'testMixed', AssertionStyle::Pest, AssertionStyle::PhpUnit],
]);

it('suggests to a weak test in the style of its first assertion, however it mixes them', function (string $description, string $suggestion): void {
    $weak = TestAssertions::in(Contents::of((string) file_get_contents(Tree::at(PHPUNIT_CART_TEST))))->of($description)
        ->weakAs(TestId::of(sprintf('Tests\\CartTest::%s', $description)), TestName::in(Path::of('tests/CartTest.php'), $description));

    expect(array_map(
        static fn(WeakTest $test): string => $test->style()->suggestion(WeaklyAsserted::by(Nameless::code(), $test)),
        iterator_to_array($weak, preserve_keys: false),
    ))->toBe([$suggestion]);
})->with([
    'expect() first' => ['testMixed', 'expect(…)->toBe(<expected>)'],
    'an assertion first' => ['testMixedBack', Weakly::PHPUNIT_OUTSIDE],
]);

it('makes no weak test of assertions that check a value, or of none', function (string $file, string $description): void {
    $assertions = TestAssertions::in(Contents::of((string) file_get_contents(Tree::at($file))))->of($description);

    expect($assertions->weakAs(TestId::of('Tests\\CartTest::testIt'), TestName::in(Path::of($file), $description)))->toHaveCount(0);
})->with([
    'a value' => [PHPUNIT_CART_TEST, 'testValue'],
    'none' => [PEST_CART_TEST, 'it does nothing'],
]);

it('reads a file once, and answers every lookup of a test from that reading', function (): void {
    $pest = TestAssertions::in(Contents::of((string) file_get_contents(Tree::at(PEST_CART_TEST))));
    $phpUnit = TestAssertions::in(Contents::of((string) file_get_contents(Tree::at(PHPUNIT_CART_TEST))));

    expect($pest->of('it adds'))->toBe($pest->of('it adds'))
        ->and($phpUnit->of('testAdds'))->toBe($phpUnit->of('TESTADDS'));
});
