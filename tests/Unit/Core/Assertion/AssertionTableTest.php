<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\AssertionKind;
use NightWorksIO\MutationGate\Core\Assertion\AssertionTable;
use NightWorksIO\MutationGate\Core\Assertion\Call;
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
        static fn(string $name): bool => AssertionTable::phpUnit(Call::of($name)) instanceof Unclassified,
    ));

    expect($unclassified)->toBe([]);
});

it('holds every method of the installed PHPUnit\'s TestCase and Assert that is no assertion as one of its own', function (): void {
    $unheld = [];

    foreach ([TestCase::class, Assert::class] as $class) {
        foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            $name = $method->getName();
            $own = str_starts_with($name, 'assert') || str_starts_with($name, 'expect') || str_starts_with($name, '__');

            if (! $own && ! AssertionTable::isCaseMethod(Call::of($name))) {
                $unheld[] = $name;
            }
        }
    }

    expect($unheld)->toBe([])
        ->and(AssertionTable::isCaseMethod(Call::of('CreateMock')))->toBeTrue()
        ->and(AssertionTable::isCaseMethod(Call::of('checkTotal')))->toBeFalse();
});

it('classifies every expectation the installed Pest declares', function (): void {
    $unclassified = array_values(array_filter(
        [...methodsStarting(Expectation::class, 'to'), ...methodsStarting(Pest\Expectation::class, 'to')],
        static fn(string $name): bool => AssertionTable::pest(Call::of($name), negated: false) instanceof Unclassified,
    ));

    expect($unclassified)->toBe([]);
});

it('classifies PHPUnit\'s assertions by what they check, in any case', function (string $assertion, AssertionKind $kind, string ...$arguments): void {
    expect(AssertionTable::phpUnit(Call::of($assertion, ...$arguments)))->toBe($kind);
})->with([
    'not null' => ['assertNotNull', AssertionKind::Existence, '$cart'],
    'not empty' => ['assertNotEmpty', AssertionKind::Existence, '$cart'],
    'true, of true' => ['assertTrue', AssertionKind::Existence, 'true'],
    'true, of TRUE, with a message' => ['assertTrue', AssertionKind::Existence, 'TRUE', "'reached'"],
    'true, of a comparison' => ['assertTrue', AssertionKind::Value, '$total > 1'],
    'not same as null' => ['assertNotSame', AssertionKind::Existence, 'NULL', '$cart'],
    'not equal to null' => ['assertNotEquals', AssertionKind::Existence, 'null', '$cart'],
    'not same as a value' => ['assertNotSame', AssertionKind::Value, '3', '$total'],
    'not same as null, second' => ['assertNotSame', AssertionKind::Existence, '$cart', 'null'],
    'true, qualified' => ['assertTrue', AssertionKind::Existence, '\\true'],
    'is array' => ['assertIsArray', AssertionKind::Shape, '$items'],
    'instance of' => ['assertInstanceOf', AssertionKind::Shape, 'Cart::class', '$cart'],
    'count' => ['assertCount', AssertionKind::Shape, '2', '$items'],
    'same' => ['assertSame', AssertionKind::Value, '3', '$total'],
    'same, in another case' => ['AssertSame', AssertionKind::Value, '3', '$total'],
    'equals' => ['assertEquals', AssertionKind::Value, '3', '$total'],
    'an expected exception' => ['expectException', AssertionKind::Value, 'RuntimeException::class'],
    'no assertion' => ['expectNotToPerformAssertions', AssertionKind::Existence],
]);

it('classifies Pest\'s expectations by what they check, negated or not, and in any case', function (string $expectation, bool $negated, AssertionKind $kind, string ...$arguments): void {
    expect(AssertionTable::pest(Call::of($expectation, ...$arguments), $negated))->toBe($kind);
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
    'be, in another case' => ['TOBE', false, AssertionKind::Value],
    'not be' => ['toBe', true, AssertionKind::Value],
    'not be null' => ['toBe', true, AssertionKind::Existence, 'null'],
    'not equal null' => ['toEqual', true, AssertionKind::Existence, 'NULL'],
    'be null' => ['toBe', false, AssertionKind::Value, 'null'],
    'equal' => ['toEqual', false, AssertionKind::Value],
    'a key' => ['toHaveKey', false, AssertionKind::Existence, "'total'"],
    'a key with its value' => ['toHaveKey', false, AssertionKind::Value, "'total'", '100'],
    'a property' => ['toHaveProperty', false, AssertionKind::Existence, "'total'"],
    'a property with its value' => ['toHaveProperty', false, AssertionKind::Value, "'total'", '100'],
    'properties by name' => ['toHaveProperties', false, AssertionKind::Existence, "['total', 'items']"],
    'snapshot' => ['toMatchSnapshot', false, AssertionKind::Value],
    'throw' => ['toThrow', false, AssertionKind::Value],
]);

it('reads properties handed their values as a check of value', function (): void {
    expect(AssertionTable::pest(Call::keyed('toHaveProperties', "['total' => 3]"), negated: false))->toBe(AssertionKind::Value);
});

it('classifies what a Pest test chains after itself: an expected exception, or nothing it holds', function (string $call, AssertionKind|Unclassified $kind): void {
    expect(AssertionTable::pestTest(Call::of($call)))->toEqual($kind);
})->with([
    'throws' => ['throws', AssertionKind::Value],
    'throws if' => ['throwsIf', AssertionKind::Value],
    'throws unless' => ['throwsUnless', AssertionKind::Value],
    'a group' => ['group', Unclassified::assertion()],
]);

it('tells a change of subject from a check', function (string $call, bool $subject): void {
    expect(AssertionTable::isSubject(Call::of($call)))->toBe($subject);
})->with([
    'and' => ['and', true],
    'json' => ['json', true],
    'an expectation' => ['toBe', false],
    'each item, called' => ['each', false],
]);

it('holds no assertion a project wrote for itself', function (): void {
    expect(AssertionTable::phpUnit(Call::of('assertMoney')))->toEqual(Unclassified::assertion())
        ->and(AssertionTable::pest(Call::of('toBeMoney'), negated: false))->toEqual(Unclassified::assertion())
        ->and(AssertionTable::pest(Call::of('toBeMoney'), negated: true))->toEqual(Unclassified::assertion());
});
