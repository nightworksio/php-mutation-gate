<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Codeception\Event\FailEvent;
use Codeception\Event\SuiteEvent;
use Codeception\Event\TestEvent;
use Codeception\Test\Cept;
use Codeception\Test\Cest;
use Codeception\Test\TestCaseWrapper;

use function dirname;

use NightWorksIO\MutationGate\Tests\Fixtures\Codeception\HeldCest;
use NightWorksIO\MutationGate\Tests\Fixtures\Codeception\HeldTest;
use PHPUnit\Framework\AssertionFailedError;

use function sprintf;

/** The tests of the Codeception fixtures, as Codeception's loader makes them, and the events Codeception dispatches of them. */
final class CodeceptionTests
{
    /** The id the Codeception runner gives a test of HeldTest, by its method. */
    public static function id(string $method): string
    {
        return sprintf('%s::%s', HeldTest::class, $method);
    }

    /** The file HeldTest is declared in, by its path on disk. */
    public static function file(): string
    {
        return sprintf('%s/Fixtures/Codeception/HeldTest.php', dirname(__DIR__));
    }

    /**
     * A test of HeldTest, by its method, as Codeception wraps a unit test.
     *
     * @param non-empty-string $method
     */
    public static function held(string $method): TestCaseWrapper
    {
        return new TestCaseWrapper(new HeldTest($method));
    }

    /**
     * A row of a test of HeldTest's data set, by its method and the row's number or name.
     *
     * @param non-empty-string $method
     */
    public static function row(string $method, int|string $row): TestCaseWrapper
    {
        $case = new HeldTest($method);
        $case->setData($row, []);

        return new TestCaseWrapper($case);
    }

    /** HeldCest's test, as Codeception loads a Cest. */
    public static function cest(): Cest
    {
        return new Cest(new HeldCest(), 'isHeld', sprintf('%s/Fixtures/Codeception/HeldCest.php', dirname(__DIR__)));
    }

    /** An example of HeldCest's test, by its index. */
    public static function example(int $index): Cest
    {
        $cest = self::cest();
        $cest->getMetadata()->setIndex($index);

        return $cest;
    }

    /** A Cept, which is no method of a class, as Codeception loads one from its file. */
    public static function cept(): Cept
    {
        return new Cept('signs in', '/project/tests/SignInCept.php');
    }

    /**
     * The event Codeception dispatches as a test of HeldTest starts, or ends after this long.
     *
     * @param non-empty-string $method
     */
    public static function event(string $method, float $time = 0.0): TestEvent
    {
        return new TestEvent(self::held($method), $time);
    }

    /**
     * The event Codeception dispatches as a test of HeldTest fails.
     *
     * @param non-empty-string $method
     */
    public static function failed(string $method): FailEvent
    {
        return new FailEvent(self::held($method), new AssertionFailedError('failed'), 0.0);
    }

    /**
     * The event Codeception dispatches as a suite of these settings starts.
     *
     * @param array<string, array<string, list<string>>> $settings
     */
    public static function suite(array $settings): SuiteEvent
    {
        return new SuiteEvent(null, $settings);
    }
}
