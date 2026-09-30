<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\Assertion;
use NightWorksIO\MutationGate\Core\Assertion\TestAssertions;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Tests\Support\Tree;

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

it('assesses no test that makes an assertion the table does not hold, makes none, or is not in the file', function (string $file, string $description): void {
    expect(readAssertions($file, $description))->toBe([[], false]);
})->with([
    'a project\'s own expectation' => [PEST_CART_TEST, 'it checks money'],
    'a project\'s own assertion' => [PHPUNIT_CART_TEST, 'testMine'],
    'no assertion' => [PEST_CART_TEST, 'it does nothing'],
    'no such test' => [PEST_CART_TEST, 'it is not there'],
    'no such method' => [PHPUNIT_CART_TEST, 'testGone'],
    'a method with no body, before one with a body' => [PHPUNIT_CART_TEST, 'testLater'],
    'a file that is not PHP' => ['not php', 'it adds'],
]);
