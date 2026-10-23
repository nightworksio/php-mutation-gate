<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\AssertionKind;
use NightWorksIO\MutationGate\Core\Assertion\AssertionTable;
use NightWorksIO\MutationGate\Core\Assertion\Call;
use NightWorksIO\MutationGate\Core\Assertion\Unclassified;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mixins\Expectation;
use PhpSpec\Matcher\IdentityMatcher;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Version;

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

/**
 * Each method of PHPUnit's `TestCase` and `Assert` a release declares, as
 * `Class::name`, from the file pinned for it.
 *
 * @return list<string>
 */
function phpUnitNames(string $release): array
{
    $lines = explode("\n", (string) file_get_contents(Tree::at(sprintf('tests/Fixtures/PhpUnit/%s.txt', $release))));

    return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
}

/**
 * The same, of the installed release, read by reflection.
 *
 * @return list<string>
 */
function installedPhpUnitNames(): array
{
    $names = [];

    foreach (['TestCase' => TestCase::class, 'Assert' => Assert::class] as $short => $class) {
        foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            $names[] = $short === 'Assert' || $method->getDeclaringClass()->getName() === $class
                ? sprintf('%s::%s', $short, $method->getName())
                : '';
        }
    }

    $names = array_values(array_unique(array_filter($names, static fn(string $name): bool => $name !== '')));
    sort($names);

    return $names;
}

/**
 * The names the table holds as PHPUnit's own.
 *
 * @return list<string>
 */
function heldAsOwn(): array
{
    $held = [];
    $value = new ReflectionClassConstant(AssertionTable::class, 'PHPUNIT_CASE')->getValue();

    foreach (is_array($value) ? $value : [] as $name) {
        $held[] = is_string($name) ? $name : '';
    }

    sort($held);

    return $held;
}

/**
 * The names among these that are no assertion: `Assert`'s `assert…` and `TestCase`'s `expect…` aside.
 *
 * @param  list<string> $names
 * @return list<string>
 */
function ownNames(array $names): array
{
    $own = [];

    foreach ($names as $qualified) {
        [$class, $name] = explode('::', $qualified);
        $assertion = ($class === 'Assert' && str_starts_with($name, 'assert')) || ($class === 'TestCase' && str_starts_with($name, 'expect'));

        if (! $assertion && ! str_starts_with($name, '__')) {
            $own[] = $name;
        }
    }

    return $own;
}

/** The releases whose names are pinned: the lowest and highest of each supported minor line. */
const PINNED_PHPUNIT = ['12.5.8', '12.5.37', '13.0.0', '13.3.4'];

it('holds as PHPUnit\'s own exactly the methods no assertion that every supported release declares', function (): void {
    $common = ownNames(phpUnitNames(PINNED_PHPUNIT[0]));

    foreach (PINNED_PHPUNIT as $release) {
        $common = array_values(array_intersect($common, ownNames(phpUnitNames($release))));
    }

    sort($common);

    expect(heldAsOwn())->toBe($common)
        ->and(AssertionTable::isCaseMethod(Call::of('CreateMock')))->toBeTrue()
        ->and(AssertionTable::isCaseMethod(Call::of('attempt')))->toBeFalse()
        ->and(AssertionTable::isCaseMethod(Call::of('checkTotal')))->toBeFalse();
});

it('classifies every assertion and expectation of every supported PHPUnit release', function (string $release): void {
    $unclassified = [];

    foreach (phpUnitNames($release) as $qualified) {
        [$class, $name] = explode('::', $qualified);
        $assertion = ($class === 'Assert' && str_starts_with($name, 'assert')) || ($class === 'TestCase' && str_starts_with($name, 'expect'));

        if ($assertion && AssertionTable::phpUnit(Call::of($name)) instanceof Unclassified) {
            $unclassified[] = $name;
        }
    }

    expect($unclassified)->toBe([]);
})->with(PINNED_PHPUNIT);

it('finds every method held as PHPUnit\'s own in the installed release', function (): void {
    expect(array_values(array_diff(heldAsOwn(), ownNames(installedPhpUnitNames()))))->toBe([]);
});

it('reads the installed PHPUnit as the file pinned for its release reads it', function (): void {
    expect(installedPhpUnitNames())->toBe(phpUnitNames(Version::id()));
})->skip(
    fn(): bool => ! in_array(Version::id(), PINNED_PHPUNIT, strict: true),
    'No file is pinned for the installed PHPUnit, such as the lowest a dependency range resolves to.',
);

it('classifies every expectation the installed Pest declares', function (): void {
    $unclassified = array_values(array_filter(
        [...methodsStarting(Expectation::class, 'to'), ...methodsStarting(Pest\Expectation::class, 'to')],
        static fn(string $name): bool => AssertionTable::pest(Call::of($name), negated: false) instanceof Unclassified,
    ));

    expect($unclassified)->toBe([]);
});

/**
 * The keywords each matcher PhpSpec builds in answers to, as their sources
 * spell them: in a list of keywords, in a list a name is checked against, or
 * compared with the name.
 *
 * @return list<string>
 */
function phpSpecKeywords(): array
{
    $keywords = [];

    $files = glob(sprintf('%s/*Matcher.php', dirname((string) new ReflectionClass(IdentityMatcher::class)->getFileName())));

    foreach (is_array($files) ? $files : [] as $file) {
        $source = (string) file_get_contents($file);
        preg_match_all('/\$keywords = \[([^\]]*)\]|in_array\(\$name, \[([^\]]*)\]|\'(\w+)\' === \$name/', $source, $found);
        preg_match_all('/\'(\w+)\'/', implode(' ', [...$found[1], ...$found[2]]), $listed);
        $keywords = [...$keywords, ...$listed[1], ...array_filter($found[3], static fn(string $word): bool => $word !== '')];
    }

    return array_values(array_unique($keywords));
}

it('classifies every matcher the installed PhpSpec builds in, negated or not', function (): void {
    $keywords = phpSpecKeywords();
    $unclassified = [];

    foreach ($keywords as $keyword) {
        foreach (['should', 'shouldNot'] as $should) {
            $matcher = sprintf('%s%s', $should, ucfirst($keyword));
            $unclassified = AssertionTable::phpSpec(Call::of($matcher)) instanceof Unclassified ? [...$unclassified, $matcher] : $unclassified;
        }
    }

    expect($unclassified)->toBe([])
        ->and($keywords)->toContain('return', 'haveType', 'beLike', 'throw');
});

it('classifies PhpSpec\'s matchers by what they check, negated or not, and in any case', function (string $matcher, AssertionKind $kind, string ...$arguments): void {
    expect(AssertionTable::phpSpec(Call::of($matcher, ...$arguments)))->toBe($kind);
})->with([
    'return' => ['shouldReturn', AssertionKind::Value, '3'],
    'return, in another case' => ['SHOULDRETURN', AssertionKind::Value, '3'],
    'not return null' => ['shouldNotReturn', AssertionKind::Existence, 'null'],
    'not be null' => ['shouldNotBe', AssertionKind::Existence, 'NULL'],
    'not be like null' => ['shouldNotBeLike', AssertionKind::Existence, 'null'],
    'not return a value' => ['shouldNotReturn', AssertionKind::Value, '3'],
    'return null' => ['shouldReturn', AssertionKind::Value, 'null'],
    'not null' => ['shouldNotBeNull', AssertionKind::Existence],
    'null' => ['shouldBeNull', AssertionKind::Value],
    'a key' => ['shouldHaveKey', AssertionKind::Existence, "'total'"],
    'a key with its value' => ['shouldHaveKeyWithValue', AssertionKind::Value, "'total'", '100'],
    'type' => ['shouldHaveType', AssertionKind::Shape, 'Cart::class'],
    'instance' => ['shouldBeAnInstanceOf', AssertionKind::Shape, 'Cart::class'],
    'count' => ['shouldHaveCount', AssertionKind::Shape, '2'],
    'a string' => ['shouldBeString', AssertionKind::Shape],
    'not a string' => ['shouldNotBeString', AssertionKind::Shape],
    'throw' => ['shouldThrow', AssertionKind::Value, 'InvalidArgumentException::class'],
    'an object\'s state' => ['shouldBeActive', AssertionKind::Value],
    'an object\'s state, negated' => ['shouldNotHaveItems', AssertionKind::Value],
    'a call a collaborator expects' => ['shouldBeCalled', AssertionKind::Value],
]);

it('holds no matcher a call that is none names, nor one named like no matcher PhpSpec builds in', function (): void {
    expect(AssertionTable::phpSpec(Call::of('add')))->toEqual(Unclassified::assertion())
        ->and(AssertionTable::phpSpec(Call::of('should')))->toEqual(Unclassified::assertion())
        ->and(AssertionTable::phpSpec(Call::of('shouldBe')))->toBe(AssertionKind::Value)
        ->and(AssertionTable::phpSpec(Call::of('shouldContainMoney')))->toEqual(Unclassified::assertion())
        ->and(AssertionTable::keyword(Call::of('shouldNotReturn')))->toBe('return')
        ->and(AssertionTable::keyword(Call::of('beConstructedWith')))->toBe('');
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
    'a group' => ['group', fn(): Unclassified => Unclassified::assertion()],
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
