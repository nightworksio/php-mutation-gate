<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\AssertionKind;
use NightWorksIO\MutationGate\Core\Assertion\AssertionTable;
use NightWorksIO\MutationGate\Core\Assertion\Unclassified;
use Pest\Mixins\Expectation;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * The public methods of a class whose names start so.
 *
 * @param  class-string $class
 * @return list<string>
 */
function methodsStarting(string $class, string $prefix): array
{
    $names = [];

    foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
        if (str_starts_with($method->getName(), $prefix)) {
            $names[] = $method->getName();
        }
    }

    return $names;
}

it('classifies every assertion and expectation the installed PHPUnit declares', function (): void {
    $unclassified = array_values(array_filter(
        [...methodsStarting(Assert::class, 'assert'), ...methodsStarting(TestCase::class, 'expect')],
        static fn(string $name): bool => AssertionTable::phpUnit($name, '') instanceof Unclassified,
    ));

    expect($unclassified)->toBe([]);
});

it('classifies every expectation the installed Pest declares', function (): void {
    $unclassified = array_values(array_filter(
        [...methodsStarting(Expectation::class, 'to'), ...methodsStarting(Pest\Expectation::class, 'to')],
        static fn(string $name): bool => AssertionTable::pest($name, negated: false) instanceof Unclassified,
    ));

    expect($unclassified)->toBe([]);
});

it('classifies PHPUnit\'s assertions by what they check', function (string $assertion, string $argument, AssertionKind $kind): void {
    expect(AssertionTable::phpUnit($assertion, $argument))->toBe($kind);
})->with([
    'not null' => ['assertNotNull', '$cart', AssertionKind::Existence],
    'not empty' => ['assertNotEmpty', '$cart', AssertionKind::Existence],
    'true, of true' => ['assertTrue', 'true', AssertionKind::Existence],
    'true, of a comparison' => ['assertTrue', '$total > 1', AssertionKind::Value],
    'is array' => ['assertIsArray', '$items', AssertionKind::Shape],
    'instance of' => ['assertInstanceOf', 'Cart::class, $cart', AssertionKind::Shape],
    'count' => ['assertCount', '2, $items', AssertionKind::Shape],
    'same' => ['assertSame', '3, $total', AssertionKind::Value],
    'equals' => ['assertEquals', '3, $total', AssertionKind::Value],
    'an expected exception' => ['expectException', 'RuntimeException::class', AssertionKind::Value],
    'no assertion' => ['expectNotToPerformAssertions', '', AssertionKind::Existence],
]);

it('classifies Pest\'s expectations by what they check, negated or not', function (string $expectation, bool $negated, AssertionKind $kind): void {
    expect(AssertionTable::pest($expectation, $negated))->toBe($kind);
})->with([
    'not null' => ['toBeNull', true, AssertionKind::Existence],
    'null' => ['toBeNull', false, AssertionKind::Value],
    'truthy' => ['toBeTruthy', false, AssertionKind::Existence],
    'not empty' => ['toBeEmpty', true, AssertionKind::Existence],
    'empty' => ['toBeEmpty', false, AssertionKind::Value],
    'not true' => ['toBeTrue', true, AssertionKind::Existence],
    'not false' => ['toBeFalse', true, AssertionKind::Existence],
    'array' => ['toBeArray', false, AssertionKind::Shape],
    'instance of' => ['toBeInstanceOf', false, AssertionKind::Shape],
    'count' => ['toHaveCount', false, AssertionKind::Shape],
    'not a string' => ['toBeString', true, AssertionKind::Shape],
    'be' => ['toBe', false, AssertionKind::Value],
    'not be' => ['toBe', true, AssertionKind::Value],
    'equal' => ['toEqual', false, AssertionKind::Value],
    'snapshot' => ['toMatchSnapshot', false, AssertionKind::Value],
    'throw' => ['toThrow', false, AssertionKind::Value],
]);

it('holds no assertion a project wrote for itself', function (): void {
    expect(AssertionTable::phpUnit('assertMoney', ''))->toEqual(Unclassified::assertion())
        ->and(AssertionTable::pest('toBeMoney', negated: false))->toEqual(Unclassified::assertion())
        ->and(AssertionTable::pest('toBeMoney', negated: true))->toEqual(Unclassified::assertion());
});
