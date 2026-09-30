<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Exception;
use PHPUnit\Event\Code\TestMethodBuilder;
use PHPUnit\Event\Code\ThrowableBuilder;
use PHPUnit\Event\Emitter;
use PHPUnit\Event\Facade;

/**
 * PHPUnit's own events, emitted through a facade of their own for the
 * subscribers registered on it. `Facade::emitter()` is static and answers the
 * running suite's emitter, so each facade's own is read from the facade.
 */
final readonly class PhpUnitEvents
{
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
