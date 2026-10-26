<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\Assertion;
use NightWorksIO\MutationGate\Core\Assertion\Assertions;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\Assertion\Helpers;
use NightWorksIO\MutationGate\Core\Assertion\TestAssertions;
use NightWorksIO\MutationGate\Core\Assertion\TestFiles;
use NightWorksIO\MutationGate\Core\Assertion\WeaklyAsserted;
use NightWorksIO\MutationGate\Core\Assertion\WeakTest;
use NightWorksIO\MutationGate\Core\File\ByPath;
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

const PHPSPEC_CART_SPEC = 'tests/Fixtures/Assertion/cart.phpspec.txt';

/**
 * The assertions of a test as written, each with what it checks, and whether they are weak.
 *
 * @return array{list<string>, bool}
 */
function readAssertions(string $file, string $description): array
{
    $text = is_file(Tree::at($file)) ? (string) file_get_contents(Tree::at($file)) : $file;
    $assertions = TestAssertions::in(Contents::of($text), Helpers::none())->of($description);

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

it('reads a spec\'s examples by their matchers, on the object it describes and on its collaborators, calls to that object asserting nothing', function (string $example, array $read): void {
    expect(readAssertions(PHPSPEC_CART_SPEC, $example))->toBe($read);
})->with([
    'a type and a value that is there' => ['it_is_initializable', [['->shouldHaveType() shape', '->shouldNotBe() existence'], true]],
    'a value beside a count' => ['it_fits', [['->shouldReturn() value', '->shouldHaveCount() shape'], false]],
    'a call a collaborator expects' => ['it_logs', [['->shouldBeCalled() value'], false]],
    'an exception during a call' => ['it_refuses', [['->shouldThrow() value'], false]],
]);

it('leaves a spec\'s example not assessed where it calls a helper the spec declares, a matcher it names, or one no table holds', function (string $example): void {
    expect(readAssertions(PHPSPEC_CART_SPEC, $example))->toBe([[], false]);
})->with(['it_fills', 'it_is_money', 'it_holds_none']);

it('reads a class that is no spec as PHPUnit\'s, where a call on $this is a helper\'s', function (): void {
    $file = str_replace('extends ObjectBehavior', 'extends TestCase', (string) file_get_contents(Tree::at(PHPSPEC_CART_SPEC)));

    expect(readAssertions($file, 'it_fits'))->toBe([[], false]);
});

it('reads a test as weak only when every assertion checks existence or shape', function (string $file, string $description, array $read): void {
    expect(readAssertions($file, $description))->toBe($read);
})->with([
    'an expected exception' => [PEST_CART_TEST, 'refuses', [['->toThrow() value'], false]],
    'a chained exception' => [PEST_CART_TEST, 'it throws', [['->throws() value'], false]],
    'a value beside a shape' => [PEST_CART_TEST, 'it mixes', [['assertSame value', '->toBeInt() shape'], false]],
    'a description with quotes' => [PEST_CART_TEST, "it escapes 'quotes'", [['->toBeArray() shape'], true]],
    'PHPUnit\'s existence and shape, in the first of two classes with the method' => [PHPUNIT_CART_TEST, 'testAdds', [['assertNotNull existence', 'assertIsArray shape', 'assertTrue existence'], true]],
    'a comparison' => [PHPUNIT_CART_TEST, 'testValue', [['assertTrue value'], false]],
    'a function of PHPUnit, and a method named like an expectation' => [PHPUNIT_CART_TEST, 'testCallsIt', [['assertCount shape'], true]],
]);

it('reads a test inside a describe() whose description is not written out as a test of its own', function (): void {
    $text = "<?php\n\ndescribe(\$name, function () {\n    it('runs inside', function () {\n        expect(1)->toBe(1);\n    });\n});\n";

    expect(readAssertions($text, 'it runs inside'))->toBe([['->toBe() value'], false]);
});

it('reads an assertion a bitwise and comes before as a call, not as the name of a function it declares', function (): void {
    $text = "<?php\n\nfinal class BitsTest extends TestCase\n{\n    public function testMasks(): void\n    {\n        \$kept = 1 & assertTrue(true);\n    }\n}\n";

    expect(readAssertions($text, 'testMasks'))->toBe([['assertTrue existence'], true]);
});

it('declares no test by a call of a method named as Pest declares one', function (): void {
    $text = "<?php\n\nit('records', function () {\n    \$recorder->it('replays', fn () => expect(1)->toBe(1));\n});\n";

    expect(readAssertions($text, 'it replays'))->toBe([[], false]);
});

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
    'the undescribed test after a nested describe' => ['it follows a nested describe', [['->toBe() value'], false]],
    'the undescribed test beside a qualified describe' => ['it is qualified', [['->toBe() value'], false]],
    'a test declared by a qualified it' => ['it qualifies its own name', [['->toBeInt() shape'], true]],
    'a key, with a trailing comma' => ['it checks a key with a trailing comma', [['->toHaveKey() existence'], true]],
]);

it('reads PHPUnit\'s assertions however a test calls them', function (string $description, array $read): void {
    expect(readAssertions(PHPUNIT_CART_TEST, $description))->toBe($read);
})->with([
    'as a qualified function' => ['testQualified', [['assertNotNull existence', 'assertSame value'], false]],
    'in another case' => ['testCased', [['assertNotNull existence', 'AssertSame value'], false]],
    'true, with a message' => ['testMessage', [['assertTrue existence'], true]],
    'not the same as null' => ['testNotSameAsNull', [['assertNotSame existence'], true]],
    'with a helper\'s name, on the subject' => ['testCallsTheSubject', [['assertNotNull existence'], true]],
    'not the same as null, second' => ['testNullSecond', [['assertNotSame existence'], true]],
    'true, qualified' => ['testQualifiedTrue', [['assertTrue existence'], true]],
    'beside PHPUnit\'s own doubles' => ['testStubs', [['assertNotNull existence'], true]],
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
    'a method of its own the file does not declare, such as a parent\'s' => [PHPUNIT_CART_TEST, 'testDelegatesToAParent'],
    'a parent\'s method, on parent' => [PHPUNIT_CART_TEST, 'testDelegatesUpward'],
    'a parent\'s method, on parent in another case' => [PHPUNIT_CART_TEST, 'testDelegatesUpwardLoudly'],
    'a method of its own, on static' => [PHPUNIT_CART_TEST, 'testDelegatesLate'],
    'a helper the file declares, on self in another case' => [PHPUNIT_CART_TEST, 'testDelegatesLoudly'],
    'a double\'s expectation of how it is called' => [PHPUNIT_CART_TEST, 'testMocks'],
    'a file that is not PHP' => ['not php', 'it adds'],
]);

it('tags each assertion with the style it is written in', function (string $file, string $description, AssertionStyle ...$styles): void {
    $assertions = TestAssertions::in(Contents::of((string) file_get_contents(Tree::at($file))), Helpers::none())->of($description);

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
    $weak = TestAssertions::in(Contents::of((string) file_get_contents(Tree::at(PHPUNIT_CART_TEST))), Helpers::none())->of($description)
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
    $assertions = TestAssertions::in(Contents::of((string) file_get_contents(Tree::at($file))), Helpers::none())->of($description);

    expect($assertions->weakAs(TestId::of('Tests\\CartTest::testIt'), TestName::in(Path::of($file), $description)))->toHaveCount(0);
})->with([
    'a value' => [PHPUNIT_CART_TEST, 'testValue'],
    'none' => [PEST_CART_TEST, 'it does nothing'],
]);

it('reads a file once, and answers every lookup of a test from that reading', function (): void {
    $pest = TestAssertions::in(Contents::of((string) file_get_contents(Tree::at(PEST_CART_TEST))), Helpers::none());
    $phpUnit = TestAssertions::in(Contents::of((string) file_get_contents(Tree::at(PHPUNIT_CART_TEST))), Helpers::none());

    expect($pest->of('it adds'))->toBe($pest->of('it adds'))
        ->and($phpUnit->of('testAdds'))->toBe($phpUnit->of('TESTADDS'));
});

it('reads a helper the files that define the runner declare as one, and a test that calls it as not assessed', function (): void {
    $file = Contents::of((string) file_get_contents(Tree::at(PEST_CART_TEST)));
    $pest = Helpers::in(Contents::of((string) file_get_contents(Tree::at('tests/Fixtures/Assertion/pest.helpers.txt'))));

    expect(TestAssertions::in($file, $pest)->of('it delegates to Pest.php')->isWeak())->toBeFalse()
        ->and(TestAssertions::in($file, $pest)->of('it delegates by reference to Pest.php')->isWeak())->toBeFalse()
        ->and(TestAssertions::in($file, Helpers::none())->of('it delegates to Pest.php')->isWeak())->toBeTrue();
});

it('assesses no test of a file the verdict did not read', function (): void {
    expect(TestFiles::read(ByPath::none(), ByPath::none())->assertionsOf(TestName::in(Path::of('tests/CartTest.php'), 'it adds')))
        ->toEqual(Assertions::notAssessed());
});
