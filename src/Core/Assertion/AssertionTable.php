<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

use function array_change_key_case;
use function array_flip;
use function array_key_exists;
use function mb_strtolower;
use function var_export;

/**
 * The fixed table of assertions, by what they check: that something is
 * there, what shape it has, or what value it holds. It holds every
 * assertion of PHPUnit's `Assert` and `TestCase` and every expectation of
 * Pest, and a test proves it does. An assertion it does not hold, such as a
 * project's own, is unclassified (ADR-0025, decision 5).
 */
final readonly class AssertionTable
{
    /** PHPUnit's assertions that something is there, or is not. */
    private const array PHPUNIT_EXISTENCE = [
        'assertNotNull',
        'assertNotEmpty',
        'assertNotFalse',
        'assertNotTrue',
        'assertArrayHasKey',
        'assertArrayNotHasKey',
        'assertObjectHasProperty',
        'assertObjectNotHasProperty',
        'assertFileExists',
        'assertFileDoesNotExist',
        'assertDirectoryExists',
        'assertDirectoryDoesNotExist',
        'assertIsReadable',
        'assertIsNotReadable',
        'assertIsWritable',
        'assertIsNotWritable',
        'assertFileIsReadable',
        'assertFileIsNotReadable',
        'assertFileIsWritable',
        'assertFileIsNotWritable',
        'assertDirectoryIsReadable',
        'assertDirectoryIsNotReadable',
        'assertDirectoryIsWritable',
        'assertDirectoryIsNotWritable',
        'expectNotToPerformAssertions',
    ];

    /** PHPUnit's assertions of a type, a class or a size. */
    private const array PHPUNIT_SHAPE = [
        'assertIsList',
        'assertContainsOnlyArray',
        'assertContainsOnlyBool',
        'assertContainsOnlyCallable',
        'assertContainsOnlyFloat',
        'assertContainsOnlyInt',
        'assertContainsOnlyIterable',
        'assertContainsOnlyNull',
        'assertContainsOnlyNumeric',
        'assertContainsOnlyObject',
        'assertContainsOnlyResource',
        'assertContainsOnlyClosedResource',
        'assertContainsOnlyScalar',
        'assertContainsOnlyString',
        'assertContainsOnlyInstancesOf',
        'assertContainsNotOnlyArray',
        'assertContainsNotOnlyBool',
        'assertContainsNotOnlyCallable',
        'assertContainsNotOnlyFloat',
        'assertContainsNotOnlyInt',
        'assertContainsNotOnlyIterable',
        'assertContainsNotOnlyNull',
        'assertContainsNotOnlyNumeric',
        'assertContainsNotOnlyObject',
        'assertContainsNotOnlyResource',
        'assertContainsNotOnlyClosedResource',
        'assertContainsNotOnlyScalar',
        'assertContainsNotOnlyString',
        'assertContainsNotOnlyInstancesOf',
        'assertCount',
        'assertNotCount',
        'assertInstanceOf',
        'assertNotInstanceOf',
        'assertIsArray',
        'assertIsBool',
        'assertIsFloat',
        'assertIsInt',
        'assertIsNumeric',
        'assertIsObject',
        'assertIsResource',
        'assertIsClosedResource',
        'assertIsString',
        'assertIsScalar',
        'assertIsCallable',
        'assertIsIterable',
        'assertIsNotArray',
        'assertIsNotBool',
        'assertIsNotFloat',
        'assertIsNotInt',
        'assertIsNotNumeric',
        'assertIsNotObject',
        'assertIsNotResource',
        'assertIsNotClosedResource',
        'assertIsNotString',
        'assertIsNotScalar',
        'assertIsNotCallable',
        'assertIsNotIterable',
        'assertSameSize',
        'assertNotSameSize',
        'assertJson',
    ];

    /** PHPUnit's assertions of a value, and its expectations of an exception or output. */
    private const array PHPUNIT_VALUE = [
        'assertArrayIsEqualToArrayOnlyConsideringListOfKeys',
        'assertArrayIsEqualToArrayIgnoringListOfKeys',
        'assertArrayIsIdenticalToArrayOnlyConsideringListOfKeys',
        'assertArrayIsIdenticalToArrayIgnoringListOfKeys',
        'assertArraysAreIdentical',
        'assertArraysAreIdenticalIgnoringOrder',
        'assertArraysHaveIdenticalValues',
        'assertArraysHaveIdenticalValuesIgnoringOrder',
        'assertArraysAreEqual',
        'assertArraysAreEqualIgnoringOrder',
        'assertArraysHaveEqualValues',
        'assertArraysHaveEqualValuesIgnoringOrder',
        'assertContains',
        'assertContainsEquals',
        'assertNotContains',
        'assertNotContainsEquals',
        'assertEquals',
        'assertEqualsCanonicalizing',
        'assertEqualsIgnoringCase',
        'assertEqualsWithDelta',
        'assertNotEquals',
        'assertNotEqualsCanonicalizing',
        'assertNotEqualsIgnoringCase',
        'assertNotEqualsWithDelta',
        'assertObjectEquals',
        'assertObjectNotEquals',
        'assertEmpty',
        'assertGreaterThan',
        'assertGreaterThanOrEqual',
        'assertLessThan',
        'assertLessThanOrEqual',
        'assertFileEquals',
        'assertFileEqualsCanonicalizing',
        'assertFileEqualsIgnoringCase',
        'assertFileEqualsFileIgnoringWhitespace',
        'assertFileNotEquals',
        'assertFileNotEqualsCanonicalizing',
        'assertFileNotEqualsIgnoringCase',
        'assertFileNotEqualsFileIgnoringWhitespace',
        'assertStringEqualsFile',
        'assertStringEqualsFileCanonicalizing',
        'assertStringEqualsFileIgnoringCase',
        'assertStringNotEqualsFile',
        'assertStringNotEqualsFileCanonicalizing',
        'assertStringNotEqualsFileIgnoringCase',
        'assertStringEqualsFileIgnoringWhitespace',
        'assertStringNotEqualsFileIgnoringWhitespace',
        'assertTrue',
        'assertFalse',
        'assertNull',
        'assertFinite',
        'assertInfinite',
        'assertNan',
        'assertSame',
        'assertNotSame',
        'assertMatchesRegularExpression',
        'assertDoesNotMatchRegularExpression',
        'assertStringContainsStringIgnoringLineEndings',
        'assertStringEqualsStringIgnoringLineEndings',
        'assertStringEqualsStringIgnoringWhitespace',
        'assertStringNotEqualsStringIgnoringWhitespace',
        'assertFileMatchesFormat',
        'assertFileMatchesFormatFile',
        'assertStringMatchesFormat',
        'assertStringMatchesFormatFile',
        'assertStringStartsWith',
        'assertStringStartsNotWith',
        'assertStringContainsString',
        'assertStringContainsStringIgnoringCase',
        'assertStringNotContainsString',
        'assertStringNotContainsStringIgnoringCase',
        'assertStringEndsWith',
        'assertStringEndsNotWith',
        'assertXmlFileEqualsXmlFile',
        'assertXmlFileNotEqualsXmlFile',
        'assertXmlStringEqualsXmlFile',
        'assertXmlStringNotEqualsXmlFile',
        'assertXmlStringEqualsXmlString',
        'assertXmlStringNotEqualsXmlString',
        'assertXmlFileEqualsXmlFileConsideringComments',
        'assertXmlFileNotEqualsXmlFileConsideringComments',
        'assertXmlStringEqualsXmlFileConsideringComments',
        'assertXmlStringNotEqualsXmlFileConsideringComments',
        'assertXmlStringEqualsXmlStringConsideringComments',
        'assertXmlStringNotEqualsXmlStringConsideringComments',
        'assertThat',
        'assertJsonStringEqualsJsonString',
        'assertJsonStringNotEqualsJsonString',
        'assertJsonStringEqualsJsonFile',
        'assertJsonStringNotEqualsJsonFile',
        'assertJsonFileEqualsJsonFile',
        'assertJsonFileNotEqualsJsonFile',
        'expectsOutput',
        'expectOutputRegex',
        'expectOutputString',
        'expectErrorLog',
        'expectException',
        'expectExceptionCode',
        'expectExceptionMessage',
        'expectExceptionMessageIs',
        'expectExceptionMessageIsOrContains',
        'expectExceptionMessageMatches',
        'expectExceptionObject',
        'expectUserDeprecationMessage',
        'expectUserDeprecationMessageMatches',
    ];

    /** Pest's expectations that something is there. */
    private const array PEST_EXISTENCE = [
        'toBeTruthy',
        'toBeFalsy',
        'toHaveKey',
        'toHaveKeys',
        'toHaveProperty',
        'toHaveProperties',
        'toBeFile',
        'toBeDirectory',
        'toBeReadableFile',
        'toBeWritableFile',
        'toBeReadableDirectory',
        'toBeWritableDirectory',
    ];

    /** Pest's expectations of a type, a class, a size, a format or an architecture. */
    private const array PEST_SHAPE = [
        'toHaveLength',
        'toHaveCount',
        'toHaveSameSize',
        'toBeInstanceOf',
        'toBeArray',
        'toBeList',
        'toBeBool',
        'toBeCallable',
        'toBeFloat',
        'toBeInt',
        'toBeIterable',
        'toBeNumeric',
        'toBeDigits',
        'toBeObject',
        'toBeResource',
        'toBeScalar',
        'toBeString',
        'toBeJson',
        'toContainOnlyInstancesOf',
        'toBeUppercase',
        'toBeLowercase',
        'toBeAlphaNumeric',
        'toBeAlpha',
        'toBeSnakeCase',
        'toBeKebabCase',
        'toBeCamelCase',
        'toBeStudlyCase',
        'toBeUuid',
        'toBeUlid',
        'toBeEmail',
        'toBeUrl',
        'toBeSlug',
        'toBeIpAddress',
        'toBeMacAddress',
        'toBeHostname',
        'toBeDomain',
        'toBeBase64',
        'toBeHexadecimal',
        'toUse',
        'toHaveFileSystemPermissions',
        'toHaveLineCountLessThan',
        'toHaveMethodsDocumented',
        'toHavePropertiesDocumented',
        'toUseStrictTypes',
        'toUseStrictEquality',
        'toBeFinal',
        'toBeReadonly',
        'toBeTrait',
        'toBeTraits',
        'toBeAbstract',
        'toHaveMethod',
        'toHaveMethods',
        'toHavePublicMethodsBesides',
        'toHavePublicMethods',
        'toHaveProtectedMethodsBesides',
        'toHaveProtectedMethods',
        'toHavePrivateMethodsBesides',
        'toHavePrivateMethods',
        'toBeCasedCorrectly',
        'toBeEnum',
        'toBeEnums',
        'toBeClass',
        'toBeClasses',
        'toBeInterface',
        'toBeInterfaces',
        'toExtend',
        'toExtendNothing',
        'toUseTrait',
        'toUseTraits',
        'toImplementNothing',
        'toOnlyImplement',
        'toHavePrefix',
        'toHaveSuffix',
        'toImplement',
        'toOnlyUse',
        'toUseNothing',
        'toHaveSuspiciousCharacters',
        'toBeUsed',
        'toBeUsedIn',
        'toOnlyBeUsedIn',
        'toBeUsedInNothing',
        'toBeInvokable',
        'toHaveSnakeCaseKeys',
        'toHaveKebabCaseKeys',
        'toHaveCamelCaseKeys',
        'toHaveStudlyCaseKeys',
        'toHaveAttribute',
        'toHaveConstructor',
        'toHaveDestructor',
        'toBeStringBackedEnums',
        'toBeIntBackedEnums',
        'toBeStringBackedEnum',
        'toBeIntBackedEnum',
    ];

    /** Pest's expectations of a value, a snapshot or an exception. */
    private const array PEST_VALUE = [
        'toBe',
        'toBeEmpty',
        'toBeTrue',
        'toBeFalse',
        'toBeGreaterThan',
        'toBeGreaterThanOrEqual',
        'toBeLessThan',
        'toBeLessThanOrEqual',
        'toContain',
        'toContainEqual',
        'toStartWith',
        'toEndWith',
        'toEqual',
        'toEqualCanonicalizing',
        'toEqualWithDelta',
        'toBeIn',
        'toBeInfinite',
        'toBeNan',
        'toBeNull',
        'toMatchArray',
        'toMatchObject',
        'toMatchSnapshot',
        'toMatch',
        'toMatchConstraint',
        'toThrow',
        'toBeBetween',
    ];

    /** Pest's expectations of a value whose negation asserts only that something is there. */
    private const array PEST_NEGATED_EXISTENCE = [
        'toBeNull',
        'toBeEmpty',
        'toBeTrue',
        'toBeFalse',
    ];

    /** Pest's expectations of a value that, negated on `null`, assert only that something is there. */
    private const array PEST_NEGATED_ON_NULL = ['toBe', 'toEqual'];

    /** PHPUnit's assertions that, on an expected `null`, assert only that something is there. */
    private const array PHPUNIT_NOT_NULL = ['assertNotSame', 'assertNotEquals'];

    /** Pest's expectations of a key or a property that check its value where they are handed one. */
    private const array PEST_VALUE_WITH_SECOND = ['toHaveKey', 'toHaveProperty'];

    /** Pest's expectations of properties that check their values where they are handed them by name. */
    private const array PEST_VALUE_WHEN_KEYED = ['toHaveProperties'];

    /** The calls a Pest test chains after itself that expect an exception. */
    private const array PEST_TEST_VALUE = ['throws', 'throwsIf', 'throwsUnless'];

    /** Pest's calls that change the subject the expectations after them check, and check nothing. */
    private const array PEST_SUBJECTS = ['and', 'json'];

    /** The assertion whose argument `true` checks only that the test got there. */
    private const array PHPUNIT_TAUTOLOGICAL = ['assertTrue'];

    /**
     * What one of PHPUnit's assertions checks. `assertTrue(true)` checks only
     * that the test got there, and `assertNotSame(null, …)` only that
     * something is.
     */
    public static function phpUnit(Call $call): AssertionKind|Unclassified
    {
        return match (true) {
            self::holds(self::PHPUNIT_TAUTOLOGICAL, $call) && $call->first() === self::true(),
            self::holds(self::PHPUNIT_NOT_NULL, $call) && $call->first() === self::null() => AssertionKind::Existence,
            self::holds(self::PHPUNIT_EXISTENCE, $call) => AssertionKind::Existence,
            self::holds(self::PHPUNIT_SHAPE, $call) => AssertionKind::Shape,
            self::holds(self::PHPUNIT_VALUE, $call) => AssertionKind::Value,
            default => Unclassified::assertion(),
        };
    }

    /**
     * What one of Pest's expectations checks, written after `->not` or
     * without it. A key or a property handed its value checks the value.
     */
    public static function pest(Call $call, bool $negated): AssertionKind|Unclassified
    {
        return match (true) {
            $negated && self::holds(self::PEST_NEGATED_EXISTENCE, $call),
            $negated && self::holds(self::PEST_NEGATED_ON_NULL, $call) && $call->first() === self::null()
                => AssertionKind::Existence,
            self::holds(self::PEST_VALUE_WITH_SECOND, $call) && $call->arguments() > 1 => AssertionKind::Value,
            self::holds(self::PEST_VALUE_WHEN_KEYED, $call) && $call->isFirstKeyed() => AssertionKind::Value,
            self::holds(self::PEST_EXISTENCE, $call) => AssertionKind::Existence,
            self::holds(self::PEST_SHAPE, $call) => AssertionKind::Shape,
            self::holds(self::PEST_VALUE, $call) => AssertionKind::Value,
            default => Unclassified::assertion(),
        };
    }

    /** What a call a Pest test chains after itself checks: an expected exception, or nothing the table holds. */
    public static function pestTest(Call $call): AssertionKind|Unclassified
    {
        return self::holds(self::PEST_TEST_VALUE, $call) ? AssertionKind::Value : Unclassified::assertion();
    }

    /** Whether a call chained after `expect()` changes the subject, and checks nothing. */
    public static function isSubject(Call $call): bool
    {
        return self::holds(self::PEST_SUBJECTS, $call);
    }

    /**
     * Whether a list holds a call's name, in any case, as PHP calls methods and functions.
     *
     * @param list<string> $names
     */
    private static function holds(array $names, Call $call): bool
    {
        return array_key_exists(mb_strtolower($call->name()), array_change_key_case(array_flip($names)));
    }

    /** PHP's `true`, as a test writes it and `Call::first()` reads it. */
    private static function true(): string
    {
        return mb_strtolower(var_export(value: true, return: true));
    }

    /** PHP's `null`, as a test writes it and `Call::first()` reads it. */
    private static function null(): string
    {
        return mb_strtolower(var_export(value: null, return: true));
    }
}
