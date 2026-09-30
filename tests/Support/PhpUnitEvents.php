<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_values;
use function count;

use Exception;
use PHPUnit\Event\Code\ClassMethod;
use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestCollection;
use PHPUnit\Event\Code\TestDox;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Code\TestMethodBuilder;
use PHPUnit\Event\Code\ThrowableBuilder;
use PHPUnit\Event\Emitter;
use PHPUnit\Event\Facade;
use PHPUnit\Event\TestData\TestDataCollection;
use PHPUnit\Event\TestSuite\TestSuiteWithName;
use PHPUnit\Framework\TestCase;
use PHPUnit\Metadata\MetadataCollection;

/**
 * PHPUnit's own events, emitted through a facade of their own for the
 * subscribers registered on it. `Facade::emitter()` is static and answers the
 * running suite's emitter, so each facade's own is read from the facade.
 */
final readonly class PhpUnitEvents
{
    /** Emits that the test running it is about to be prepared. */
    public static function started(Facade $events): void
    {
        $events->seal();
        self::emitterOf($events)->testPreparationStarted(TestMethodBuilder::fromCallStack());
    }

    /** Emits that the test running it was skipped. */
    public static function skipped(Facade $events): void
    {
        $events->seal();
        self::emitterOf($events)->testSkipped(TestMethodBuilder::fromCallStack(), 'skipped');
    }

    /** Emits that the test running it was marked incomplete. */
    public static function incomplete(Facade $events): void
    {
        $events->seal();
        self::emitterOf($events)->testMarkedAsIncomplete(
            TestMethodBuilder::fromCallStack(),
            ThrowableBuilder::from(new Exception('incomplete')),
        );
    }

    /** Emits that a suite of these tests was skipped whole. */
    public static function suiteSkipped(Facade $events, Test ...$tests): void
    {
        $events->seal();
        self::emitterOf($events)->testSuiteSkipped(
            new TestSuiteWithName('skipped whole', count($tests), TestCollection::fromArray(array_values($tests))),
            'skipped',
        );
    }

    /**
     * Emits that a class's `setUpBeforeClass` errored.
     *
     * @param class-string<TestCase> $class
     */
    public static function beforeClassErrored(Facade $events, string $class): void
    {
        $events->seal();
        self::emitterOf($events)->beforeFirstTestMethodErrored(
            $class,
            new ClassMethod($class, 'setUpBeforeClass'),
            ThrowableBuilder::from(new Exception('before class')),
        );
    }

    /**
     * Emits that a class's `setUpBeforeClass` failed.
     *
     * @param class-string<TestCase> $class
     */
    public static function beforeClassFailed(Facade $events, string $class): void
    {
        $events->seal();
        self::emitterOf($events)->beforeFirstTestMethodFailed(
            $class,
            new ClassMethod($class, 'setUpBeforeClass'),
            ThrowableBuilder::from(new Exception('before class')),
        );
    }

    /**
     * A test method of a class, by the class's name and the method's.
     *
     * @param class-string<TestCase> $class
     * @param non-empty-string       $method
     */
    public static function test(string $class, string $method): TestMethod
    {
        return new TestMethod(
            $class,
            $method,
            __FILE__,
            1,
            new TestDox($class, $method, $method),
            MetadataCollection::fromArray([]),
            TestDataCollection::fromArray([]),
        );
    }

    /** Emits that the test running it failed. */
    public static function failed(Facade $events): void
    {
        $events->seal();
        self::emitterOf($events)->testFailed(
            TestMethodBuilder::fromCallStack(),
            ThrowableBuilder::from(new Exception('failed')),
            null,
        );
    }

    /** Emits that the test running it errored. */
    public static function errored(Facade $events): void
    {
        $events->seal();
        self::emitterOf($events)->testErrored(
            TestMethodBuilder::fromCallStack(),
            ThrowableBuilder::from(new Exception('errored')),
        );
    }

    /** Emits that the test running it passed. */
    public static function passed(Facade $events): void
    {
        $events->seal();
        self::emitterOf($events)->testPassed(TestMethodBuilder::fromCallStack());
    }

    /** Emits that the test running it finished, having made one assertion. */
    public static function finished(Facade $events): void
    {
        $events->seal();
        self::emitterOf($events)->testFinished(TestMethodBuilder::fromCallStack(), 1);
    }

    private static function emitterOf(Facade $events): Emitter
    {
        return (fn(): Emitter => $this->emitter)->call($events);
    }
}
